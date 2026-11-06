<?php

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\base\Utility as UtilityBase;
use DateTimeImmutable;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;
use WeakMap;

/**
 * Shows the team and usage of the Lettermint team of the team API token, and the statistics and domains of the project
 * of the token (Pro edition).
 */
class Utility extends UtilityBase
{
	/**
	 * The URL of the dashboard of Lettermint.
	 */
	public const DASHBOARD_URL = 'https://dash.lettermint.co/';

	/**
	 * The seconds the data is cached for, so the utility and the widget do not load it from Lettermint each time.
	 */
	public const CACHE_DURATION = 300;

	/**
	 * The data of the page for each app, as its content, toolbar and footer are rendered separately.
	 *
	 * @var WeakMap<object,array<string,mixed>>|null
	 */
	private static ?WeakMap $data = null;

	public static function displayName(): string
	{
		return Craft::t('lettermint', 'Lettermint');
	}

	public static function id(): string
	{
		return 'lettermint';
	}

	public static function icon(): string
	{
		return __DIR__ . '/icon-mask.svg';
	}

	public static function isSelectable(): bool
	{
		return Plugin::isPro();
	}

	/**
	 * Whether the current user may see the data of the utility, on its page, in the widget or by refreshing it.
	 */
	public static function isAccessible(): bool
	{
		return self::isSelectable() && Craft::$app->getUtilities()->checkAuthorization(self::class);
	}

	public static function contentHtml(): string
	{
		return self::render('content');
	}

	public static function toolbarHtml(): string
	{
		return self::render('toolbar');
	}

	public static function footerHtml(): string
	{
		return self::render('footer');
	}

	/**
	 * The adapter of the email settings, or null when Lettermint is not the transport.
	 */
	public static function adapter(): ?Adapter
	{
		return Adapter::fromMailSettings();
	}

	private static function render(string $part): string
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(UtilityAsset::class);

		return $view->renderTemplate("lettermint/utility/{$part}", self::data());
	}

	/**
	 * The data of the page and the widget, from the cache when it was loaded within the last minutes.
	 *
	 * It is kept for each app as well, as the content, toolbar and footer are rendered separately.
	 *
	 * @return array<string,mixed>
	 */
	public static function data(): array
	{
		self::$data ??= new WeakMap();

		if (isset(self::$data[Craft::$app])) {
			return self::$data[Craft::$app];
		}

		$key = self::cacheKey();
		$cache = Craft::$app->getCache();

		return self::$data[Craft::$app] = $key !== null && $cache !== null
			? $cache->getOrSet($key, static fn() => self::fetch(), self::CACHE_DURATION)
			: self::fetch();
	}

	/**
	 * Clears the cached data, so it is loaded from Lettermint again.
	 */
	public static function refresh(): void
	{
		if (null !== $key = self::cacheKey()) {
			Craft::$app->getCache()?->delete($key);
		}

		if (self::$data !== null) {
			unset(self::$data[Craft::$app]);
		}
	}

	/**
	 * The key of the cached data, or null when Lettermint is not the transport, as there is nothing to cache then.
	 */
	private static function cacheKey(): ?string
	{
		return self::adapter()?->cacheKey('utility');
	}

	/**
	 * Loads the data of the page from Lettermint.
	 *
	 * @return array<string,mixed>
	 */
	private static function fetch(): array
	{
		$adapter = self::adapter();
		$token = $adapter?->getTeamToken();
		$projectId = $adapter?->getProjectId();
		$sections = $token !== null ? (new TeamApi($token))->overview($projectId) : null;
		$invalid = Overview::invalid($sections);
		$project = $sections !== null ? Overview::project($adapter, $projectId, $sections) : null;

		// The project and its domains are kept as the project, so they are not cached and passed to the page twice.
		if ($sections !== null) {
			unset($sections['project'], $sections['domains']);
		}

		return [
			// What is missing before the team can be shown, if anything.
			'setup' => match (true) {
				$adapter === null => 'transport',
				$token === null => 'token',
				$invalid => 'invalid',
				default => null,
			},
			'tokens' => $adapter !== null ? [
				'token' => $adapter->checkToken()?->value,
				'teamToken' => Overview::teamTokenStatus($sections)?->value,
			] : null,
			'sections' => $sections,
			'usage' => Overview::usage($sections),
			'project' => $project,
			'statsDays' => TeamApi::STATS_DAYS,
			'dashboardUrl' => self::DASHBOARD_URL,
			'loadedAt' => new DateTimeImmutable(),
		];
	}
}
