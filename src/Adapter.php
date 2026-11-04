<?php

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use craft\helpers\MailerHelper;
use craft\mail\transportadapters\BaseTransportAdapter;
use Lettermint\Exceptions\AuthenticationException;
use Lettermint\Exceptions\LettermintConfigException;
use Lettermint\Exceptions\LettermintException;
use Lettermint\SymfonyMailer\Transport\LettermintApiTransport;
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
	 * The settings of each site.
	 */
	private const SITE_KEYS = ['route', 'tags'];

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
	 * Lettermint does not tell the project of a token, so it is set here, or taken from a team with a single project.
	 */
	public ?string $project = null;

	/**
	 * The signing secret of the Lettermint webhook that posts to the webhook endpoint (Pro edition).
	 */
	public ?string $webhookSecret = null;

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
		$this->sites = self::normalizeSites($this->sites);
	}

	public function attributeLabels(): array
	{
		return [
			'token' => Craft::t('lettermint', 'Token'),
			'teamToken' => Craft::t('lettermint', 'Team Token'),
			'project' => Craft::t('lettermint', 'Project'),
			'webhookSecret' => Craft::t('lettermint', 'Webhook Secret'),
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
			'attributes' => ['token', 'teamToken', 'project', 'webhookSecret', 'route', 'tags'],
		];

		return $behaviors;
	}

	public function rules(): array
	{
		$strings = ['token', 'teamToken', 'project', 'webhookSecret', 'route', 'tags'];

		return [
			[['token'], 'required'],
			[$strings, 'string'],
			[$strings, 'trim'],
			[['tags'], 'validateTags'],
			[['sites'], 'filter', 'filter' => self::normalizeSites(...)],
			[['sites'], 'validateSiteTags'],
		];
	}

	/**
	 * Validates the format of the tags, which hold their parsed value while they are validated.
	 */
	public function validateTags(string $attribute): void
	{
		foreach (Tags::errors($this->$attribute) as $error) {
			$this->addError($attribute, $error);
		}
	}

	/**
	 * Validates the format of the tags of each site, with environment variables resolved.
	 *
	 * The tags of a site are also counted together with the tags of the adapter, as they are merged when sending.
	 */
	public function validateSiteTags(string $attribute): void
	{
		$adapterTags = $this->getTagList();

		foreach ($this->sites as $uid => $options) {
			$siteTags = App::parseEnv($options['tags'] ?? null);
			$errors = Tags::errors($siteTags);

			// Too many tags of the adapter are reported on the adapter itself.
			if ($errors === [] && count($adapterTags) <= Tags::MAX && count(Tags::merge($adapterTags, Tags::parse($siteTags))) > Tags::MAX) {
				$errors[] = Craft::t('lettermint', 'There can be at most {max} tags together with the tags above.', ['max' => Tags::MAX]);
			}

			if ($errors === []) {
				continue;
			}

			$site = self::siteName($uid);

			foreach ($errors as $error) {
				$this->addError($attribute, "{$site}: {$error}");
			}
		}
	}

	/**
	 * The route, with environment variables resolved, or null when it has none.
	 */
	public function getRoute(): ?string
	{
		return self::env($this->route);
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
		return self::env($this->sites[$siteUid]['route'] ?? null);
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
		return Plugin::isPro() ? self::env($this->teamToken) : null;
	}

	/**
	 * The project ID, with environment variables resolved, or null when it has none or in the standard edition.
	 */
	public function getProjectId(): ?string
	{
		return Plugin::isPro() ? self::env($this->project) : null;
	}

	/**
	 * The webhook signing secret, with environment variables resolved, or null when it has none or in the standard edition.
	 */
	public function getWebhookSecret(): ?string
	{
		return Plugin::isPro() ? self::env($this->webhookSecret) : null;
	}

	/**
	 * The adapter of the email settings, or null when Lettermint is not the transport.
	 */
	public static function fromMailSettings(): ?self
	{
		$settings = App::mailSettings();

		if ($settings->transportType !== self::class) {
			return null;
		}

		/** @var self */
		return MailerHelper::createTransportAdapter(self::class, $settings->transportSettings);
	}

	public function getSettingsHtml(): ?string
	{
		$rows = [];

		foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
			$rows[$site->uid] = [
				'heading' => $site->getName(),
				'route' => $this->sites[$site->uid]['route'] ?? '',
				'tags' => $this->sites[$site->uid]['tags'] ?? '',
			];
		}

		return Craft::$app->getView()->renderTemplate('lettermint/settings', [
			'adapter' => $this,
			'pro' => Plugin::isPro(),
			'siteRows' => $rows,
			'projectSuggestions' => $this->projectSuggestions(),
			'webhookUrl' => Webhooks::url(),
		]);
	}

	/**
	 * Checks the token, with environment variables resolved, with Lettermint.
	 *
	 * @return TokenStatus|null The status of the token, or null when there is no token to check.
	 */
	public function checkToken(): ?TokenStatus
	{
		$token = App::parseEnv($this->token);

		if (!is_string($token) || $token === '') {
			return null;
		}

		try {
			ClientFactory::project($token, self::PING_TIMEOUT)->ping();
		} catch (AuthenticationException|LettermintConfigException) {
			// The SDK refuses tokens that cannot be sent as a header, such as ones with spaces.
			return TokenStatus::Invalid;
		} catch (LettermintException) {
			return TokenStatus::Unknown;
		}

		return TokenStatus::Valid;
	}

	public function defineTransport(): AbstractTransport
	{
		$options = array_filter([
			'route' => $this->getRoute(),
			'tags' => $this->getTagList(),
		]);

		$dispatcher = null;

		if (Plugin::isPro() && $this->sites !== []) {
			$dispatcher = new EventDispatcher();
			$dispatcher->addListener(MessageEvent::class, new SiteOptions($this));
		}

		return new LettermintApiTransport(
			projectToken: App::parseEnv($this->token),
			dispatcher: $dispatcher,
			options: $options,
		);
	}

	/**
	 * The projects of the team as suggestions for the project, which are none when Lettermint rejects the team token.
	 *
	 * @return list<array{label:string,data:list<array{name:string,hint:string}>}>
	 */
	private function projectSuggestions(): array
	{
		$teamToken = $this->getTeamToken();
		$projects = $teamToken !== null ? (new TeamApi($teamToken))->projects() : [];

		if ($projects === []) {
			return [];
		}

		return [[
			'label' => Craft::t('lettermint', 'Projects'),
			'data' => array_map(static fn (array $project) => [
				'name' => $project['id'],
				'hint' => $project['name'],
			], $projects),
		]];
	}

	/**
	 * Reduces the sites to the routes and tags that are set, dropping sites without any.
	 *
	 * @return array<string,array{route?:string,tags?:string}>
	 */
	private static function normalizeSites(mixed $sites): array
	{
		$normalized = [];

		foreach (is_array($sites) ? $sites : [] as $uid => $options) {
			if (!is_string($uid) || !is_array($options)) {
				continue;
			}

			$site = [];

			foreach (self::SITE_KEYS as $key) {
				if (null !== $value = self::nonEmpty($options[$key] ?? null)) {
					$site[$key] = $value;
				}
			}

			if ($site !== []) {
				$normalized[$uid] = $site;
			}
		}

		return $normalized;
	}

	private static function siteName(string $uid): string
	{
		try {
			return Craft::$app->getSites()->getSiteByUid($uid, true)->getName();
		} catch (SiteNotFoundException) {
			return $uid;
		}
	}

	/**
	 * A value with environment variables resolved, trimmed, or null when it is empty.
	 */
	private static function env(?string $value): ?string
	{
		return self::nonEmpty(App::parseEnv($value));
	}

	private static function nonEmpty(mixed $value): ?string
	{
		return is_string($value) && trim($value) !== '' ? trim($value) : null;
	}
}
