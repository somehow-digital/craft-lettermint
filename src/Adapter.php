<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\helpers\MailerHelper;
use craft\mail\transportadapters\BaseTransportAdapter;
use Lettermint\Exceptions\AuthenticationException;
use Lettermint\Exceptions\LettermintConfigException;
use Lettermint\Exceptions\LettermintException;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class Adapter extends BaseTransportAdapter
{
	/**
	 * The seconds to wait for Lettermint when checking a token, which keeps the settings page from hanging.
	 */
	private const PING_TIMEOUT = 5;

	/**
	 * The fewest characters of the health token, which keeps it from being guessed.
	 */
	public const HEALTH_TOKEN_MIN_LENGTH = 16;

	/**
	 * The settings that can be set through environment variables.
	 */
	private const ENV_ATTRIBUTES = ['token', 'teamToken', 'project', 'webhookSecret', 'healthToken', 'route', 'tags'];

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
	public ?string $project = null;

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
			'token' => Craft::t('lettermint', 'Token'),
			'teamToken' => Craft::t('lettermint', 'Team Token'),
			'project' => Craft::t('lettermint', 'Project'),
			'webhookSecret' => Craft::t('lettermint', 'Webhook Secret'),
			'healthToken' => Craft::t('lettermint', 'Health Token'),
			'route' => Craft::t('lettermint', 'Route'),
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
			// The tags hold their parsed value while they are validated.
			[['tags'], fn() => $this->addErrors(['tags' => Tags::errors($this->tags)])],
			[['healthToken'], 'validateHealthToken'],
			[['sites'], 'filter', 'filter' => Sites::normalize(...)],
			[['sites'], fn() => $this->addErrors(['sites' => Sites::tagErrors($this->sites, $this->getTagList())])],
		];
	}

	/**
	 * Validates the length of the health token, with environment variables resolved, and that it can be sent as a
	 * bearer token.
	 */
	public function validateHealthToken(string $attribute): void
	{
		$token = Env::value($this->healthToken);

		if ($token === null) {
			return;
		}

		if (mb_strlen($token) < self::HEALTH_TOKEN_MIN_LENGTH) {
			$this->addError($attribute, Craft::t('lettermint', 'The health token must be at least {min} characters long.', [
				'min' => self::HEALTH_TOKEN_MIN_LENGTH,
			]));
		}

		if (preg_match('/\s/u', $token) === 1) {
			$this->addError($attribute, Craft::t('lettermint', 'The health token cannot contain spaces.'));
		}
	}

	/**
	 * The route, with environment variables resolved, or null when it has none.
	 */
	public function getRoute(): ?string
	{
		return Env::value($this->route);
	}

	/**
	 * The tags, with environment variables resolved.
	 *
	 * @return list<array{name:string,value:string}>
	 */
	public function getTagList(): array
	{
		return Tags::parse(App::parseEnv($this->tags));
	}

	/**
	 * The route of a site, with environment variables resolved, or null when it has none.
	 */
	public function getSiteRoute(string $siteUid): ?string
	{
		return Env::value($this->sites[$siteUid]['route'] ?? null);
	}

	/**
	 * The tags of a site, with environment variables resolved.
	 *
	 * @return list<array{name:string,value:string}>
	 */
	public function getSiteTags(string $siteUid): array
	{
		return Tags::parse(App::parseEnv($this->sites[$siteUid]['tags'] ?? null));
	}

	/**
	 * The team API token, with environment variables resolved, or null when it has none or in the standard edition.
	 */
	public function getTeamToken(): ?string
	{
		return $this->proValue($this->teamToken);
	}

	/**
	 * The project ID, with environment variables resolved, or null when it has none or in the standard edition.
	 */
	public function getProjectId(): ?string
	{
		return $this->proValue($this->project);
	}

	/**
	 * The webhook signing secret, with environment variables resolved, or null when it has none or in the standard edition.
	 */
	public function getWebhookSecret(): ?string
	{
		return $this->proValue($this->webhookSecret);
	}

	/**
	 * The health token, with environment variables resolved, or null when it has none or in the standard edition.
	 */
	public function getHealthToken(): ?string
	{
		return $this->proValue($this->healthToken);
	}

	/**
	 * A setting of the pro edition, with environment variables resolved, or null when it is empty or in the standard edition.
	 */
	private function proValue(?string $value): ?string
	{
		return Plugin::isPro() ? Env::value($value) : null;
	}

	/**
	 * Whether Lettermint is the transport of the email settings.
	 */
	public static function isMailTransport(): bool
	{
		return App::mailSettings()->transportType === self::class;
	}

	/**
	 * The adapter of the email settings, or null when Lettermint is not the transport.
	 */
	public static function fromMailSettings(): ?self
	{
		if (!self::isMailTransport()) {
			return null;
		}

		$settings = App::mailSettings();

		/** @var self */
		return MailerHelper::createTransportAdapter(self::class, $settings->transportSettings);
	}

	/**
	 * A key for data cached for these settings, which other settings, another system email address or another
	 * version of the plugin change, as the plugin may shape the data differently.
	 *
	 * The settings are hashed with environment variables resolved, so a changed value, such as a rotated token,
	 * changes the key as well.
	 */
	public function cacheKey(string $name): string
	{
		$values = [
			Plugin::getInstance()?->getVersion(),
			$this->getAttributes(),
			App::mailSettings()->fromEmail,
		];

		array_walk_recursive($values, static function (mixed &$value): void {
			if (is_string($value)) {
				$value = App::parseEnv($value);
			}
		});

		return "lettermint.{$name}." . hash('sha256', serialize($values));
	}

	public function getSettingsHtml(): string
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

		$view = Craft::$app->getView();

		// The styles hold the badge of the pro settings.
		if (Plugin::isPro()) {
			$view->registerAssetBundle(UtilityAsset::class);
		}

		return $view->renderTemplate('lettermint/settings', [
			'adapter' => $this,
			'pro' => Plugin::isPro(),
			'siteRows' => $rows,
			'webhookUrl' => Webhooks::url(),
			'healthUrl' => Health::url(),
			'healthTokenMinLength' => self::HEALTH_TOKEN_MIN_LENGTH,
		]);
	}

	/**
	 * Checks the token, with environment variables resolved, with Lettermint.
	 *
	 * @return TokenStatus|null The status of the token, or null when there is no token to check.
	 */
	public function checkToken(): ?TokenStatus
	{
		// The token is checked as the transport sends it, untrimmed.
		$token = App::parseEnv($this->token);

		if (!is_string($token) || $token === '') {
			return null;
		}

		try {
			ClientFactory::project($token, self::PING_TIMEOUT)->ping();

			return TokenStatus::Valid;
		} catch (AuthenticationException|LettermintConfigException) {
			// The SDK refuses tokens that cannot be sent as a header, such as ones with spaces.
			return TokenStatus::Invalid;
		} catch (LettermintException) {
			return TokenStatus::Unknown;
		}
	}

	public function defineTransport(): AbstractTransport
	{
		$options = array_filter([
			'route' => $this->getRoute(),
			'tags' => $this->getTagList(),
		], static fn(mixed $option): bool => $option !== null && $option !== []);

		$dispatcher = null;

		if (Plugin::isPro() && $this->sites !== []) {
			$dispatcher = new EventDispatcher();
			$dispatcher->addListener(MessageEvent::class, new SiteOptions($this));
		}

		$token = App::parseEnv($this->token);

		return ClientFactory::transport(is_string($token) ? $token : '', $dispatcher, $options);
	}
}
