<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\mail\transportadapters\BaseTransportAdapter;
use Lettermint\SymfonyMailer\Transport\LettermintApiTransport;
use SomehowDigital\Craft\Lettermint\enums\Transport;
use SomehowDigital\Craft\Lettermint\helpers\Env;
use SomehowDigital\Craft\Lettermint\helpers\HealthEndpoint;
use SomehowDigital\Craft\Lettermint\helpers\Sites;
use SomehowDigital\Craft\Lettermint\mail\SiteOptions;
use SomehowDigital\Craft\Lettermint\mail\SmtpOptions;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\SiteSettings;
use SomehowDigital\Craft\Lettermint\models\Tags;
use SomehowDigital\Craft\Lettermint\validators\HealthTokenValidator;
use SomehowDigital\Craft\Lettermint\validators\SiteTagsValidator;
use SomehowDigital\Craft\Lettermint\validators\TagsValidator;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Lettermint transport adapter for Craft's email settings.
 *
 * The plugin passes in the edition on creation, so it depends only on its settings.
 */
final class Adapter extends BaseTransportAdapter
{
	/**
	 * Settings that accept environment variables.
	 */
	private const ENV_ATTRIBUTES = ['token', 'transport', 'port', 'teamToken', 'projectId', 'webhookSecret', 'healthToken', 'route', 'tags'];

	private const SMTP_HOST = 'smtp.lettermint.co';

	/**
	 * Allowed SMTP ports, with whether they use implicit TLS rather than STARTTLS.
	 */
	private const SMTP_PORTS = [465 => true, 587 => false, 2465 => true, 2587 => false];

	private const SMTP_USERNAME = 'lettermint';

	public static function displayName(): string
	{
		return 'Lettermint';
	}

	public ?string $token = null;

	/**
	 * How emails are sent: `api` or `smtp`.
	 */
	public string $transport = Transport::Api->value;

	/**
	 * SMTP port, 465 by default.
	 */
	public ?string $port = null;

	/**
	 * Team API token (Pro edition).
	 */
	public ?string $teamToken = null;

	/**
	 * ID of the token's project, shown in the utility (Pro edition).
	 *
	 * Lettermint doesn't expose a token's project. Without it, only the team is shown.
	 */
	public ?string $projectId = null;

	/**
	 * Signing secret of the Lettermint webhook (Pro edition).
	 */
	public ?string $webhookSecret = null;

	/**
	 * Bearer token of the health endpoint (Pro edition).
	 */
	public ?string $healthToken = null;

	public ?string $route = null;

	/**
	 * Tags as comma-separated name=value pairs, e.g. `environment=production, campaign=welcome`.
	 */
	public ?string $tags = null;

	/**
	 * The route and tags of each site, keyed by site UID (Pro edition).
	 *
	 * @var array<string,array{route?:string,tags?:string}>
	 */
	public array $sites = [];

	/**
	 * Set by the plugin through the container, not a setting.
	 */
	private bool $pro = false;

	/**
	 * @internal
	 */
	public function setPro(bool $pro): void
	{
		$this->pro = $pro;
	}

	public function init(): void
	{
		parent::init();

		// Craft stores posted settings as is.
		$this->sites = Sites::normalize($this->sites);
	}

	public function attributeLabels(): array
	{
		return [
			'token' => Craft::t('lettermint', 'Project API Token'),
			'transport' => Craft::t('lettermint', 'Transport'),
			'port' => Craft::t('lettermint', 'Port'),
			'teamToken' => Craft::t('lettermint', 'Team API Token'),
			'projectId' => Craft::t('lettermint', 'Project'),
			'webhookSecret' => Craft::t('lettermint', 'Webhook Secret'),
			'healthToken' => Craft::t('lettermint', 'Health Token'),
			'route' => Craft::t('lettermint', 'Route Slug'),
			'tags' => Craft::t('lettermint', 'Tags'),
			'sites' => Craft::t('lettermint', 'Sites'),
		];
	}

	public function behaviors(): array
	{
		$behaviors = parent::behaviors();

		$behaviors['parser'] = [
			'class' => EnvAttributeParserBehavior::class,
			'attributes' => self::ENV_ATTRIBUTES,
		];

		return $behaviors;
	}

	/**
	 * @return list<array<mixed>>
	 */
	public function rules(): array
	{
		return [
			[['token'], 'required'],
			[self::ENV_ATTRIBUTES, 'trim'],
			[['transport'], 'in', 'range' => array_column(Transport::cases(), 'value')],
			[['port'], 'in', 'range' => array_keys(self::SMTP_PORTS)],
			// Validated with env vars parsed; empty values are skipped.
			[['tags'], TagsValidator::class],
			[['healthToken'], HealthTokenValidator::class],
			[['sites'], 'filter', 'filter' => Sites::normalize(...)],
			[['sites'], SiteTagsValidator::class, 'siteNames' => $this->siteNames()],
		];
	}

	/**
	 * Settings with env vars resolved, and Pro settings dropped in the standard edition.
	 *
	 * @internal
	 */
	public function resolve(): Settings
	{
		$pro = $this->pro;
		$proValue = static fn(?string $value): ?string => $pro ? Env::value($value) : null;
		// Untrimmed, as the transport sends it as is.
		$token = App::parseEnv($this->token);
		$mail = App::mailSettings();
		$port = (int) Env::value($this->port);
		$port = isset(self::SMTP_PORTS[$port]) ? $port : Settings::DEFAULT_PORT;

		return new Settings(
			token: is_string($token) && $token !== '' ? $token : null,
			transport: Transport::tryFrom((string) Env::value($this->transport)) ?? Transport::Api,
			port: $port,
			route: Env::value($this->route),
			tags: Tags::parse(App::parseEnv($this->tags)),
			sites: $pro ? $this->resolveSites($mail->siteOverrides) : [],
			teamToken: $proValue($this->teamToken),
			projectId: $proValue($this->projectId),
			webhookSecret: $proValue($this->webhookSecret),
			healthToken: $proValue($this->healthToken),
			senderEmail: Env::value($mail->fromEmail),
			senderName: Env::value($mail->fromName),
			replyToEmail: Env::value($mail->replyToEmail),
			template: Env::value($mail->template),
		);
	}

	/**
	 * Sites with their own settings, or with overrides of Craft's email settings, by site UID.
	 *
	 * @param array<mixed> $overrides
	 * @return array<array-key,SiteSettings>
	 */
	private function resolveSites(array $overrides): array
	{
		$sites = [];

		foreach ([...array_keys($this->sites), ...array_keys($overrides)] as $uid) {
			$mail = $overrides[$uid] ?? [];
			$sites[$uid] = SiteSettings::resolve($this->sites[$uid] ?? [], is_array($mail) ? $mail : []);
		}

		return $sites;
	}

	public function getSettingsHtml(): string
	{
		$view = Craft::$app->getView();
		$pro = $this->pro;
		$actionTrigger = Craft::$app->getConfig()->getGeneral()->actionTrigger;

		// For the Pro badge.
		if ($pro) {
			$view->registerAssetBundle(UtilityAsset::class);
		}

		return $view->renderTemplate('lettermint/settings', [
			'adapter' => $this,
			'transports' => [
				['value' => Transport::Api->value, 'label' => Craft::t('lettermint', 'API')],
				['value' => Transport::Smtp->value, 'label' => Craft::t('lettermint', 'SMTP')],
			],
			'ports' => $this->portOptions(),
			'pro' => $pro,
			'siteRows' => $this->siteRows(),
			'webhookUrl' => UrlHelper::siteUrl("{$actionTrigger}/lettermint/webhook"),
			'healthUrl' => HealthEndpoint::url($actionTrigger),
			'healthTokenMinLength' => HealthTokenValidator::MIN_LENGTH,
		]);
	}

	/**
	 * @return list<array{value:int,label:string}>
	 */
	private function portOptions(): array
	{
		$options = [];

		foreach (self::SMTP_PORTS as $port => $implicitTls) {
			$mode = $implicitTls ? Craft::t('lettermint', 'Implicit TLS') : Craft::t('lettermint', 'STARTTLS');
			$options[] = ['value' => $port, 'label' => "{$port} — {$mode}"];
		}

		return $options;
	}

	/**
	 * Route and tags of each saved site, including disabled ones, by site UID.
	 *
	 * @return array<string,array{heading:string,route:string,tags:string}>
	 */
	private function siteRows(): array
	{
		$rows = [];

		foreach ($this->siteNames() as $uid => $name) {
			$rows[$uid] = [
				'heading' => $name,
				'route' => $this->sites[$uid]['route'] ?? '',
				'tags' => $this->sites[$uid]['tags'] ?? '',
			];
		}

		return $rows;
	}

	/**
	 * Names of all saved sites, including disabled ones, by site UID.
	 *
	 * @return array<string,string>
	 */
	private function siteNames(): array
	{
		return Sites::names(Craft::$app->getSites()->getAllSites(true));
	}

	public function defineTransport(): AbstractTransport
	{
		$settings = $this->resolve();

		return match ($settings->transport) {
			Transport::Api => $this->defineApiTransport($settings),
			Transport::Smtp => $this->defineSmtpTransport($settings),
		};
	}

	private function defineApiTransport(Settings $settings): AbstractTransport
	{
		$options = array_filter([
			'route' => $settings->route,
			'tags' => $settings->tags->toArray(),
		], static fn(mixed $option): bool => $option !== null && $option !== []);

		$dispatcher = null;

		// Only set in the Pro edition.
		if ($settings->sites !== []) {
			$dispatcher = new EventDispatcher();
			$dispatcher->addListener(MessageEvent::class, new SiteOptions($settings));
		}

		// Without Craft's proxy, the client falls back to the environment's, like Craft's client.
		// `config/guzzle.php` holds Guzzle options, so only the proxy applies.
		$client = HttpClient::create(['proxy' => Env::nonEmpty(Craft::$app->getConfig()->getGeneral()->httpProxy)]);

		return new LettermintApiTransport(projectToken: $settings->token ?? '', client: $client, dispatcher: $dispatcher, options: $options);
	}

	private function defineSmtpTransport(Settings $settings): AbstractTransport
	{
		$dispatcher = new EventDispatcher();
		$dispatcher->addListener(MessageEvent::class, new SmtpOptions($settings));

		$transport = new EsmtpTransport(self::SMTP_HOST, $settings->port, self::SMTP_PORTS[$settings->port], $dispatcher);
		// Never falls back to plain text after STARTTLS.
		$transport->setRequireTls(true);
		$transport->setUsername(self::SMTP_USERNAME);
		$transport->setPassword($settings->token ?? '');

		return $transport;
	}
}
