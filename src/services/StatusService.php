<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use Craft;
use craft\helpers\App;
use craft\helpers\MailerHelper;
use DateTimeImmutable;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\api\LettermintApi;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\models\CheckResult;
use SomehowDigital\Craft\Lettermint\models\Checks;
use SomehowDigital\Craft\Lettermint\models\HealthReport;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\TeamApiData;
use SomehowDigital\Craft\Lettermint\models\UtilityData;
use yii\base\Component;

/**
 * Provides the resolved settings, the health report and the utility data, all checked the same way.
 *
 * The health report is cached briefly to stay within Lettermint's rate limits. Utility data goes stale after a few
 * minutes but is kept longer, so it can be shown while reloading.
 *
 * @internal
 */
final class StatusService extends Component
{
	public const HEALTH_DURATION = 60;

	public const FRESH_DURATION = 300;

	public const STALE_DURATION = 604800;

	/**
	 * Part of the cache keys, as other versions may shape the data differently.
	 */
	public ?string $version = null;

	/**
	 * Per request, as the content and footer render separately.
	 */
	private ?UtilityData $data = null;

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly LettermintApi $api,
		array $config = [],
	) {
		parent::__construct($config); // @pest-mutate-ignore: RemoveMethodCall
	}

	/**
	 * Whether Lettermint is the email transport.
	 */
	public function isLettermint(): bool
	{
		return App::mailSettings()->transportType === Adapter::class;
	}

	/**
	 * Null when Lettermint is not the email transport.
	 */
	public function settings(): ?Settings
	{
		if (!$this->isLettermint()) {
			return null;
		}

		/** @var Adapter $adapter */
		$adapter = MailerHelper::createTransportAdapter(Adapter::class, App::mailSettings()->transportSettings);

		return $adapter->resolve();
	}

	/**
	 * Cached per settings unless `$fresh`.
	 */
	public function health(Settings $settings, bool $fresh = false): HealthReport
	{
		$check = function () use ($settings): HealthReport {
			[, $api, $checks] = $this->check($settings, false);

			return new HealthReport($checks->all(), $api?->usageSummary(), new DateTimeImmutable());
		};

		$cache = Craft::$app->getCache();

		return $fresh || $cache === null ? $check() : $cache->getOrSet($this->cacheKey($settings, 'health'), $check, self::HEALTH_DURATION);
	}

	/**
	 * Cached data, fresh or stale, or null when there is none.
	 *
	 * Without Lettermint as the transport, there's nothing to load, so this never returns null.
	 */
	public function cached(): ?UtilityData
	{
		if ($this->data instanceof UtilityData) {
			return $this->data;
		}

		$settings = $this->settings();

		if (!$settings instanceof Settings) {
			return $this->data = UtilityData::from(null, null, null);
		}

		$data = Craft::$app->getCache()?->get($this->cacheKey($settings, 'utility'));

		return $this->data = $data instanceof UtilityData ? $data : null;
	}

	public function isFresh(UtilityData $data, DateTimeImmutable $now = new DateTimeImmutable()): bool
	{
		return $data->loadedAt > $now->modify(sprintf('-%d seconds', self::FRESH_DURATION));
	}

	/**
	 * Fresh data, reloaded unless the cached data is fresh.
	 */
	public function data(): UtilityData
	{
		$data = $this->cached();

		return $data instanceof UtilityData && $this->isFresh($data) ? $data : $this->reload();
	}

	/**
	 * Transient failures are not cached, so the next request retries.
	 */
	public function reload(): UtilityData
	{
		$settings = $this->settings();

		if (!$settings instanceof Settings) {
			return $this->data = UtilityData::from(null, null, null);
		}

		[$tokenStatus, $api, $checks] = $this->check($settings, true);
		$data = UtilityData::from($settings, $api, $checks);

		if ($tokenStatus !== TokenStatus::Unknown && $api?->unavailable() !== true) {
			Craft::$app->getCache()?->set($this->cacheKey($settings, 'utility'), $data, self::STALE_DURATION);
		}

		return $this->data = $data;
	}

	/**
	 * Changes with the resolved settings (e.g. a rotated token) and the plugin version.
	 */
	public function cacheKey(Settings $settings, string $name): string
	{
		return "lettermint.{$name}." . hash('sha256', serialize([$this->version, $settings]));
	}

	/**
	 * Includes disabled sites, as they may still send emails.
	 *
	 * @return array{0:?TokenStatus,1:?TeamApiData,2:CheckResult}
	 */
	private function check(Settings $settings, bool $withStats): array
	{
		$api = $settings->teamToken !== null ? $this->api->teamApi($settings->teamToken, $settings->projectId, $withStats) : null;
		$tokenStatus = $this->api->tokenStatus($settings->token);

		return [$tokenStatus, $api, Checks::build($settings, $tokenStatus, $api, Craft::$app->getSites()->getAllSites(true))];
	}
}
