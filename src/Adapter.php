<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\mail\transportadapters\BaseTransportAdapter;
use SomehowDigital\Craft\Lettermint\api\ClientFactory;
use SomehowDigital\Craft\Lettermint\helpers\Env;
use SomehowDigital\Craft\Lettermint\helpers\Sites;
use SomehowDigital\Craft\Lettermint\mail\SiteOptions;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\SiteSettings;
use SomehowDigital\Craft\Lettermint\models\Tags;
use SomehowDigital\Craft\Lettermint\services\HealthService;
use SomehowDigital\Craft\Lettermint\services\WebhooksService;
use SomehowDigital\Craft\Lettermint\validators\HealthTokenValidator;
use SomehowDigital\Craft\Lettermint\validators\SiteTagsValidator;
use SomehowDigital\Craft\Lettermint\validators\TagsValidator;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class Adapter extends BaseTransportAdapter
{
	/**
	 * The settings that can be set through environment variables.
	 */
	private const ENV_ATTRIBUTES = ['token', 'teamToken', 'projectId', 'webhookSecret', 'healthToken', 'route', 'tags'];

	public static function displayName(): string
	{
		return 'Lettermint';
	}

	public ?string $token = null;

	/**
	 * The team API token, for the team API of Lettermint (Pro edition).
	 */
	public ?string $teamToken = null;

	/**
	 * The ID of the Lettermint project of the token, which the utility shows (Pro edition).
	 *
	 * Lettermint does not tell the project of a token, so it is set here. Without it, only the team is shown.
	 */
	public ?string $projectId = null;

	/**
	 * The signing secret of the Lettermint webhook that posts to the webhook endpoint (Pro edition).
	 */
	public ?string $webhookSecret = null;

	/**
	 * The token that requests to the health endpoint authenticate with (Pro edition).
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

	public function init(): void
	{
		parent::init();

		// Craft stores the posted settings as they are, so they are normalized when the adapter is created.
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
			// The settings are typed as strings already, so they only need trimming.
			[self::ENV_ATTRIBUTES, 'trim'],
			// The settings hold their parsed value while they are validated, and empty ones are not validated.
			[['tags'], TagsValidator::class],
			[['healthToken'], HealthTokenValidator::class],
			[['sites'], 'filter', 'filter' => Sites::normalize(...)],
			[['sites'], SiteTagsValidator::class],
		];
	}

	/**
	 * The settings as they apply, with environment variables resolved and the settings of the pro edition left out in
	 * the standard edition.
	 *
	 * The adapter does not know the mailer it belongs to, so the email address that mailer sends from is passed in.
	 *
	 * @param string|null $senderEmail The email address the mailer of the adapter sends from, which may be an
	 * environment variable.
	 * @internal
	 */
	public function resolve(?string $senderEmail = null): Settings
	{
		$pro = Plugin::isPro();
		$proValue = static fn(?string $value): ?string => $pro ? Env::value($value) : null;
		// The token is kept untrimmed, as the transport sends it as it is.
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
			senderEmail: Env::value($senderEmail),
		);
	}

	public function getSettingsHtml(): string
	{
		$view = Craft::$app->getView();
		$pro = Plugin::isPro();

		// The styles hold the badge of the pro settings.
		if ($pro) {
			$view->registerAssetBundle(UtilityAsset::class);
		}

		return $view->renderTemplate('lettermint/settings', [
			'adapter' => $this,
			'pro' => $pro,
			'siteRows' => $this->siteRows(),
			'webhookUrl' => WebhooksService::url(),
			'healthUrl' => HealthService::url(),
			'healthTokenMinLength' => HealthTokenValidator::MIN_LENGTH,
		]);
	}

	/**
	 * A row of the route and tags of each saved site, also the disabled ones, keyed by site UID.
	 *
	 * @return array<string,array{heading:string,route:string,tags:string}>
	 */
	private function siteRows(): array
	{
		$rows = [];

		foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
			if ($site->uid === null) {
				continue;
			}

			$rows[$site->uid] = [
				'heading' => $site->getName(),
				'route' => $this->sites[$site->uid]['route'] ?? '',
				'tags' => $this->sites[$site->uid]['tags'] ?? '',
			];
		}

		return $rows;
	}

	public function defineTransport(): AbstractTransport
	{
		$settings = $this->resolve();

		$options = array_filter([
			'route' => $settings->route,
			'tags' => $settings->tags->toArray(),
		], static fn(mixed $option): bool => $option !== null && $option !== []);

		$dispatcher = null;

		// The sites are only resolved in the pro edition.
		if ($settings->sites !== []) {
			$dispatcher = new EventDispatcher();
			$dispatcher->addListener(MessageEvent::class, new SiteOptions($settings));
		}

		return ClientFactory::transport($settings->token ?? '', $dispatcher, $options);
	}
}
