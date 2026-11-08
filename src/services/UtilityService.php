<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use Craft;
use DateTimeImmutable;
use SomehowDigital\Craft\Lettermint\enums\CheckType;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\models\ApiResult;
use SomehowDigital\Craft\Lettermint\models\Check;
use SomehowDigital\Craft\Lettermint\models\CheckResult;
use SomehowDigital\Craft\Lettermint\models\Domain;
use SomehowDigital\Craft\Lettermint\models\Period;
use SomehowDigital\Craft\Lettermint\models\Project;
use SomehowDigital\Craft\Lettermint\models\Route;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\Stats;
use SomehowDigital\Craft\Lettermint\models\StatsDay;
use SomehowDigital\Craft\Lettermint\models\StatsTotals;
use SomehowDigital\Craft\Lettermint\models\Team;
use SomehowDigital\Craft\Lettermint\models\TeamApiData;
use SomehowDigital\Craft\Lettermint\models\Usage;
use SomehowDigital\Craft\Lettermint\models\UtilityData;
use SomehowDigital\Craft\Lettermint\Plugin;
use yii\base\Component;

/**
 * Loads the team and usage of the team API token, and the statistics and domains of the project of the token, for the
 * utility and the widget (Pro edition), through the team API and the checks of the `checks` component.
 *
 * The data is fresh for a few minutes. Older data is kept for longer, so the utility and the widget show it right away
 * while they load it again.
 *
 * The plugin registers it as its `utility` component.
 *
 * @internal
 */
final class UtilityService extends Component
{
	/**
	 * The seconds the data is fresh for, so the utility and the widget do not load it from Lettermint each time.
	 */
	public const CACHE_DURATION = 300;

	/**
	 * The seconds the data is kept for, to be shown while it is loaded again.
	 */
	public const STALE_DURATION = 604800;

	/**
	 * The data of the page for this request, as its content and footer are rendered separately.
	 */
	private ?UtilityData $data = null;

	/**
	 * The data that can be shown without loading it from Lettermint, fresh or not, or null when there is none.
	 *
	 * Without Lettermint as the transport, there is nothing to load, so the data is always there.
	 */
	public function cached(): ?UtilityData
	{
		if ($this->data instanceof UtilityData) {
			return $this->data;
		}

		$settings = Plugin::instance()->getMailSettings()->settings();

		if (!$settings instanceof Settings) {
			return $this->data = UtilityData::from(null, null, null);
		}

		$data = Craft::$app->getCache()?->get($this->cacheKey($settings));

		return $this->data = $data instanceof UtilityData ? $data : null;
	}

	/**
	 * Whether the data was loaded within the last minutes, so it is not loaded again.
	 */
	public function isFresh(UtilityData $data, DateTimeImmutable $now = new DateTimeImmutable()): bool
	{
		return $data->loadedAt > $now->modify(sprintf('-%d seconds', self::CACHE_DURATION));
	}

	/**
	 * The fresh data of the page and the widget, loaded from Lettermint unless it is.
	 */
	public function data(): UtilityData
	{
		$data = $this->cached();

		return $data instanceof UtilityData && $this->isFresh($data) ? $data : $this->reload();
	}

	/**
	 * Loads the data from Lettermint again, and keeps it, unless Lettermint could not be reached for any of it, so it is
	 * loaded again with the next request instead of keeping the failure.
	 */
	public function reload(): UtilityData
	{
		$plugin = Plugin::instance();
		$settings = $plugin->getMailSettings()->settings();

		// Without Lettermint as the transport, there is nothing to load or keep.
		if (!$settings instanceof Settings) {
			return $this->data = UtilityData::from(null, null, null);
		}

		$checks = $plugin->getChecks();
		$api = $checks->teamApi($settings, withStats: true);
		$tokenStatus = $checks->tokenStatus($settings->token);
		$data = UtilityData::from($settings, $api, $checks->checks($settings, $tokenStatus, $api));

		if ($tokenStatus !== TokenStatus::Unknown && $api?->unavailable() !== true) {
			Craft::$app->getCache()?->set($this->cacheKey($settings), $data, self::STALE_DURATION);
		}

		return $this->data = $data;
	}

	/**
	 * Data in the layout that the data of the settings is likely shown in, for the utility and the widget to show while
	 * nothing is cached.
	 */
	public function placeholder(): UtilityData
	{
		return self::placeholderFor(Plugin::instance()->getMailSettings()->settings());
	}

	/**
	 * The key of the data of the settings in the cache.
	 */
	public function cacheKey(Settings $settings): string
	{
		return Plugin::instance()->getMailSettings()->cacheKey($settings, 'utility');
	}

	/**
	 * Data in the layout that the data of the settings is likely shown in, so the page does not shift once the data is
	 * there.
	 *
	 * @param Settings|null $settings The settings, or null when Lettermint is not the transport.
	 * @param int $statsDays The days of the statistics.
	 */
	public static function placeholderFor(?Settings $settings, int $statsDays = Stats::DAYS, DateTimeImmutable $now = new DateTimeImmutable()): UtilityData
	{
		$ok = static fn(CheckType $type): Check => Check::ok($type, 'Loading from Lettermint…');

		if ($settings?->teamToken === null) {
			return UtilityData::from($settings, null, $settings instanceof Settings ? new CheckResult($ok(CheckType::Token)) : null, $now);
		}

		$date = static fn(string $modifier): string => $now->modify($modifier)->format('Y-m-d');
		// The numbers only fill the layout, so any others would do as well.
		$team = new Team(name: 'Team', plan: 'plan', includedVolume: 10000, verifiedAt: $date('-1 year'), createdAt: $date('-1 year')); // @pest-mutate-ignore: IncrementInteger, DecrementInteger
		$usage = new Usage(new Period(used: 1000, start: $date('-15 days'), end: $date('+15 days'))); // @pest-mutate-ignore: IncrementInteger, DecrementInteger
		$project = null;
		$stats = null;

		if ($settings->projectId !== null) {
			$project = ApiResult::loaded(new Project(
				id: $settings->projectId,
				name: 'Project',
				routes: [new Route(slug: 'outgoing', default: true)],
				domains: [new Domain(domain: 'example.com', status: Domain::STATUS_VERIFIED, statusChangedAt: $date('-1 month'))],
			));

			$daily = [];

			for ($day = $statsDays - 1; $day >= 0; --$day) {
				$daily[] = new StatsDay($date("-{$day} days"), 10 + $day % 7 * 5); // @pest-mutate-ignore: IncrementInteger, DecrementInteger
			}

			$stats = ApiResult::loaded(new Stats(
				from: $date(sprintf('-%d days', $statsDays - 1)),
				to: $date('today'),
				totals: new StatsTotals(sent: 1000, delivered: 980, hardBounced: 10, spamComplaints: 1), // @pest-mutate-ignore: IncrementInteger, DecrementInteger
				daily: $daily,
			));
		}

		return UtilityData::from($settings, new TeamApiData(ApiResult::loaded($team), ApiResult::loaded($usage), $project, $stats), new CheckResult(
			token: $ok(CheckType::Token),
			teamToken: $ok(CheckType::TeamToken),
			projectChecks: $project instanceof ApiResult ? [$ok(CheckType::Delivery), $ok(CheckType::Sender), $ok(CheckType::Route)] : [],
		), $now);
	}
}
