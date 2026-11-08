<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\mail\transportadapters\BaseTransportAdapter;
use Lettermint\SymfonyMailer\Transport\LettermintApiTransport;
use SomehowDigital\Craft\Lettermint\helpers\Env;
use SomehowDigital\Craft\Lettermint\helpers\Sites;
use SomehowDigital\Craft\Lettermint\mail\SiteOptions;
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
	private const ENV_ATTRIBUTES = ['token', 'teamToken', 'projectId', 'webhookSecret', 'healthToken', 'route', 'tags'];

	public static function displayName(): string
	{
		return 'Lettermint';
	}

	public ?string $token = null;

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

		return new Settings(
			token: is_string($token) && $token !== '' ? $token : null,
			route: Env::value($this->route),
			tags: Tags::parse(App::parseEnv($this->tags)),
			sites: $pro ? array_map(SiteSettings::resolve(...), $this->sites) : [],
			teamToken: $proValue($this->teamToken),
			projectId: $proValue($this->projectId),
			webhookSecret: $proValue($this->webhookSecret),
			healthToken: $proValue($this->healthToken),
			senderEmail: Env::value(App::mailSettings()->fromEmail),
		);
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
			'pro' => $pro,
			'siteRows' => $this->siteRows(),
			'webhookUrl' => UrlHelper::siteUrl("{$actionTrigger}/lettermint/webhook"),
			'healthUrl' => UrlHelper::siteUrl("{$actionTrigger}/lettermint/health"),
			'healthTokenMinLength' => HealthTokenValidator::MIN_LENGTH,
		]);
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
		$names = [];

		foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
			if ($site->uid !== null) {
				$names[$site->uid] = $site->getName();
			}
		}

		return $names;
	}

	public function defineTransport(): AbstractTransport
	{
		$settings = $this->resolve();

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
}
