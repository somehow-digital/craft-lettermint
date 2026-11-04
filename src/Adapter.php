<?php

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use craft\mail\transportadapters\BaseTransportAdapter;
use Lettermint\SymfonyMailer\Transport\LettermintApiTransport;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Adapter extends BaseTransportAdapter
{
	/**
	 * The most tags Lettermint accepts for a message.
	 */
	private const MAX_TAGS = 20;

	/**
	 * The endpoint of Lettermint that checks a token.
	 */
	private const PING_URL = 'https://api.lettermint.co/v1/ping';

	/**
	 * The seconds to wait for Lettermint when checking a token, which keeps the settings page from hanging.
	 */
	private const PING_TIMEOUT = 5;

	public static function displayName(): string
	{
		return 'Lettermint';
	}

	public ?string $token = null;

	/**
	 * The team API token, for the team API of Lettermint (Pro edition).
	 */
	public ?string $teamToken = null;

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
		$this->sites = $this->normalizeSites($this->sites);
	}

	public function attributeLabels(): array
	{
		return [
			'token' => Craft::t('lettermint', 'Token'),
			'teamToken' => Craft::t('lettermint', 'Team Token'),
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
			'attributes' => [
				'token',
				'teamToken',
				'route',
				'tags',
			],
		];

		return $behaviors;
	}

	public function rules(): array
	{
		return [
			[['token'], 'required'],
			[['token', 'teamToken', 'route', 'tags'], 'string'],
			[['token', 'teamToken', 'route', 'tags'], 'trim'],
			[['tags'], 'validateTags'],
			[['sites'], 'filter', 'filter' => $this->normalizeSites(...)],
			[['sites'], 'validateSiteTags'],
		];
	}

	/**
	 * Validates the format of the tags, which hold their parsed value while they are validated.
	 */
	public function validateTags(string $attribute): void
	{
		foreach (self::tagErrors($this->$attribute) as $error) {
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
			$errors = self::tagErrors($siteTags);

			// Too many tags of the adapter are reported on the adapter itself.
			if ($errors === [] && count($adapterTags) <= self::MAX_TAGS && count(self::mergeTags($adapterTags, self::parseTags($siteTags))) > self::MAX_TAGS) {
				$errors[] = Craft::t('lettermint', 'There can be at most {max} tags together with the tags above.', ['max' => self::MAX_TAGS]);
			}

			if ($errors === []) {
				continue;
			}

			try {
				$site = Craft::$app->getSites()->getSiteByUid($uid, true)->getName();
			} catch (SiteNotFoundException) {
				$site = $uid;
			}

			foreach ($errors as $error) {
				$this->addError($attribute, "{$site}: {$error}");
			}
		}
	}

	/**
	 * The tags, with environment variables resolved.
	 *
	 * @return list<array{name:string,value:string}>
	 */
	public function getTagList(): array
	{
		return self::parseTags(App::parseEnv($this->tags));
	}

	/**
	 * The tags of a site, with environment variables resolved.
	 *
	 * @return list<array{name:string,value:string}>
	 */
	public function getSiteTags(string $siteUid): array
	{
		return self::parseTags(App::parseEnv($this->sites[$siteUid]['tags'] ?? null));
	}

	/**
	 * The team API token, with environment variables resolved, or null when it has none or in the standard edition.
	 */
	public function getTeamToken(): ?string
	{
		if (!self::isPro()) {
			return null;
		}

		$token = App::parseEnv($this->teamToken);

		return is_string($token) && trim($token) !== '' ? trim($token) : null;
	}

	/**
	 * The route of a site, with environment variables resolved, or null when it has none.
	 */
	public function getSiteRoute(string $siteUid): ?string
	{
		$route = App::parseEnv($this->sites[$siteUid]['route'] ?? null);

		return is_string($route) && trim($route) !== '' ? trim($route) : null;
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
			'pro' => self::isPro(),
			'siteRows' => $rows,
			'tokenStatus' => $this->checkToken()?->value,
			'teamTokenStatus' => self::isPro() ? $this->checkTeamToken()?->value : null,
		]);
	}

	/**
	 * Checks the token, with environment variables resolved, with Lettermint.
	 *
	 * @return TokenStatus|null The status of the token, or null when there is no token to check.
	 */
	public function checkToken(): ?TokenStatus
	{
		return self::ping(App::parseEnv($this->token), static fn (string $token) => ['x-lettermint-token' => $token]);
	}

	/**
	 * Checks the team token, with environment variables resolved, with Lettermint.
	 *
	 * @return TokenStatus|null The status of the team token, or null when there is no team token to check.
	 */
	public function checkTeamToken(): ?TokenStatus
	{
		return self::ping(App::parseEnv($this->teamToken), static fn (string $token) => ['authorization' => "Bearer {$token}"]);
	}

	/**
	 * Pings Lettermint with a token, which the ping endpoint accepts as a project or a team token.
	 *
	 * @param callable(string):array<string,string> $headers The headers that authenticate with the token.
	 */
	private static function ping(mixed $token, callable $headers): ?TokenStatus
	{
		if (!is_string($token) || $token === '') {
			return null;
		}

		// The transport refuses such tokens, which also cannot be sent as a header.
		if (trim($token) !== $token || preg_match('/[\x00-\x1f\x7f]/', $token)) {
			return TokenStatus::Invalid;
		}

		try {
			$status = self::httpClient()->request('GET', self::PING_URL, [
				'headers' => $headers($token),
				'timeout' => self::PING_TIMEOUT,
				'max_duration' => self::PING_TIMEOUT,
				'max_redirects' => 0,
			])->getStatusCode();
		} catch (ExceptionInterface) {
			return TokenStatus::Unknown;
		}

		return match ($status) {
			200 => TokenStatus::Valid,
			401 => TokenStatus::Invalid,
			default => TokenStatus::Unknown,
		};
	}

	public function defineTransport(): AbstractTransport
	{
		$route = App::parseEnv($this->route);
		$tags = $this->getTagList();

		$options = [];

		if ($route) {
			$options['route'] = $route;
		}

		if ($tags !== []) {
			$options['tags'] = $tags;
		}

		$dispatcher = null;

		if (self::isPro() && $this->sites !== []) {
			$dispatcher = new EventDispatcher();
			$dispatcher->addListener(MessageEvent::class, $this->applySiteOptions(...));
		}

		return new LettermintApiTransport(
			projectToken: App::parseEnv($this->token),
			dispatcher: $dispatcher,
			options: $options,
		);
	}

	/**
	 * Applies the route and tags of the current site to a message, which Craft sets to the site of the message while sending.
	 *
	 * The tags of the site are added to the tags of the adapter, replacing the ones with the same name.
	 * Options set on the message itself take precedence over the ones of the site.
	 */
	private function applySiteOptions(MessageEvent $event): void
	{
		$email = $event->getMessage();

		if (!$email instanceof Email) {
			return;
		}

		try {
			$site = Craft::$app->getSites()->getCurrentSite();
		} catch (SiteNotFoundException) {
			return;
		}

		$options = [];

		if (null !== $route = $this->getSiteRoute($site->uid)) {
			$options['route'] = $route;
		}

		if ([] !== $tags = $this->getSiteTags($site->uid)) {
			$options['tags'] = self::mergeTags($this->getTagList(), $tags);
		}

		if ($options === []) {
			return;
		}

		$headers = $email->getHeaders();

		if (null !== $header = $headers->get('X-LM-Options')) {
			$overrides = json_decode((string) $header->getBody(), true);

			// Invalid options are left to the transport, which rejects them.
			if (!is_array($overrides)) {
				return;
			}

			$options = array_replace($options, $overrides);
			$headers->remove('X-LM-Options');
		}

		$headers->addTextHeader('X-LM-Options', json_encode($options, JSON_THROW_ON_ERROR));
	}

	/**
	 * Reduces the sites to the routes and tags that are set, dropping sites without any.
	 *
	 * @return array<string,array{route?:string,tags?:string}>
	 */
	private function normalizeSites(mixed $sites): array
	{
		$normalized = [];

		foreach (is_array($sites) ? $sites : [] as $uid => $options) {
			if (!is_string($uid) || !is_array($options)) {
				continue;
			}

			$site = [];

			foreach (['route', 'tags'] as $key) {
				$value = $options[$key] ?? null;

				if (is_string($value) && trim($value) !== '') {
					$site[$key] = trim($value);
				}
			}

			if ($site !== []) {
				$normalized[$uid] = $site;
			}
		}

		return $normalized;
	}

	/**
	 * Splits tags into their trimmed name=value pairs, leaving out empty entries.
	 *
	 * @return list<array{0:string,1:?string}> The name and value of each pair, with a null value for entries without `=`.
	 */
	private static function splitTags(mixed $tags): array
	{
		$pairs = [];

		foreach (explode(',', is_string($tags) ? $tags : '') as $entry) {
			$entry = trim($entry);

			if ($entry === '') {
				continue;
			}

			$parts = explode('=', $entry, 2);
			$pairs[] = [trim($parts[0]), isset($parts[1]) ? trim($parts[1]) : null];
		}

		return $pairs;
	}

	/**
	 * Parses tags into the name/value pairs Lettermint expects, skipping entries without `=`.
	 *
	 * Lettermint validates the tags itself, so the ones set through environment variables are sent as they are.
	 *
	 * @return list<array{name:string,value:string}>
	 */
	private static function parseTags(mixed $tags): array
	{
		$parsed = [];

		foreach (self::splitTags($tags) as [$name, $value]) {
			if ($value !== null) {
				$parsed[] = ['name' => $name, 'value' => $value];
			}
		}

		return self::mergeTags($parsed);
	}

	/**
	 * Merges lists of tags, a later tag replacing an earlier one with the same name.
	 *
	 * @param list<array{name:string,value:string}> ...$lists
	 * @return list<array{name:string,value:string}>
	 */
	private static function mergeTags(array ...$lists): array
	{
		$merged = [];

		foreach ($lists as $tags) {
			foreach ($tags as $tag) {
				$merged[$tag['name']] = $tag;
			}
		}

		return array_values($merged);
	}

	/**
	 * The errors of tags, following the rules of Lettermint for tag names and values.
	 *
	 * @return list<string>
	 */
	private static function tagErrors(mixed $tags): array
	{
		$errors = [];
		$names = [];

		foreach (self::splitTags($tags) as [$name, $value]) {
			if ($value === null) {
				$errors[] = Craft::t('lettermint', '“{tag}” is not a name=value pair.', ['tag' => $name]);
				continue;
			}

			if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $name)) {
				$errors[] = Craft::t('lettermint', 'Tag name “{name}” must be 1 to 32 letters, digits, underscores or hyphens.', ['name' => $name]);
			} elseif (stripos($name, '__lettermint') === 0) {
				$errors[] = Craft::t('lettermint', 'Tag name “{name}” is reserved.', ['name' => $name]);
			} elseif (isset($names[$name])) {
				$errors[] = Craft::t('lettermint', 'Tag name “{name}” is used more than once.', ['name' => $name]);
			}

			if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value)) {
				$errors[] = Craft::t('lettermint', 'Tag value “{value}” must be 1 to 64 letters, digits, underscores or hyphens.', ['value' => $value]);
			}

			$names[$name] = true;
		}

		if (count($names) > self::MAX_TAGS) {
			$errors[] = Craft::t('lettermint', 'There can be at most {max} tags.', ['max' => self::MAX_TAGS]);
		}

		return $errors;
	}

	/**
	 * The HTTP client for checking tokens, which can be replaced through the container.
	 */
	private static function httpClient(): HttpClientInterface
	{
		if (Craft::$container->has(HttpClientInterface::class)) {
			return Craft::$container->get(HttpClientInterface::class);
		}

		return HttpClient::create();
	}

	private static function isPro(): bool
	{
		return Plugin::getInstance()?->is(Plugin::EDITION_PRO) ?? false;
	}
}
