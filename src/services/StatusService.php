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
 * The status of Lettermint as the transport of the email settings: its settings, the health report of the health
 * endpoint and the console command, and the data of the utility and the widget (Pro edition), which all check the
 * settings with Lettermint the same way.
 *
 * The health report is cached for a minute, so frequent monitoring does not run into the rate limits of Lettermint. The
 * data of the utility is fresh for a few minutes, and older data is kept for longer, so the utility and the widget show
 * it right away while they load it again.
 *
 * The plugin registers it as its `status` component, and configures it with its version through the container.
 *
 * @internal
 */
final class StatusService extends Component
{
	/**
	 * The seconds a health report is cached for.
	 */
	public const HEALTH_DURATION = 60;

	/**
	 * The seconds the data of the utility is fresh for, so the utility and the widget do not load it each time.
	 */
	public const FRESH_DURATION = 300;

	/**
	 * The seconds the data of the utility is kept for, to be shown while it is loaded again.
	 */
	public const STALE_DURATION = 604800;

	/**
	 * The version of the plugin, which changes the keys of the cached data, as another version may shape it differently.
	 */
	public ?string $version = null;

	/**
	 * The data of the utility for this request, as the content and the footer of its page are rendered separately.
	 */
	private ?UtilityData $data = null;

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly LettermintApi $api,
		array $config = [],
	) {
		// Yii configures the component, which takes only its version, event handlers and behaviors from its config here.
		parent::__construct($config); // @pest-mutate-ignore: RemoveMethodCall
	}

	/**
	 * Whether the adapter of the plugin is the transport of the email settings.
	 */
	public function isLettermint(): bool
	{
		return App::mailSettings()->transportType === Adapter::class;
	}

	/**
	 * The resolved settings of the adapter of the email settings, or null when it is not the adapter of the plugin.
	 */
	public function settings(): ?Settings
	{
		if (!$this->isLettermint()) {
			return null;
		}

		/** @var Adapter $adapter Craft creates the adapter of the class it is given. */
		$adapter = MailerHelper::createTransportAdapter(Adapter::class, App::mailSettings()->transportSettings);

		return $adapter->resolve();
	}

	/**
	 * The health report of the settings, from the cache when they were checked within the last minute, unless it is to
	 * be fresh. Other settings, or another system email address, are checked again right away.
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
	 * The data of the utility that can be shown without loading it from Lettermint, fresh or not, or null when there is
	 * none.
	 *
	 * Without Lettermint as the transport, there is nothing to load, so the data is always there.
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

	/**
	 * Whether the data of the utility was loaded within the last minutes, so it is not loaded again.
	 */
	public function isFresh(UtilityData $data, DateTimeImmutable $now = new DateTimeImmutable()): bool
	{
		return $data->loadedAt > $now->modify(sprintf('-%d seconds', self::FRESH_DURATION));
	}

	/**
	 * The fresh data of the utility, loaded from Lettermint unless it is.
	 */
	public function data(): UtilityData
	{
		$data = $this->cached();

		return $data instanceof UtilityData && $this->isFresh($data) ? $data : $this->reload();
	}

	/**
	 * Loads the data of the utility from Lettermint again, and keeps it, unless Lettermint could not be reached for any
	 * of it, so it is loaded again with the next request instead of keeping the failure.
	 */
	public function reload(): UtilityData
	{
		$settings = $this->settings();

		// Without Lettermint as the transport, there is nothing to load or keep.
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
	 * A key for data cached for settings, which other settings, including another system email address, or another
	 * version of the plugin change, as the plugin may shape the data differently.
	 *
	 * The settings are resolved, so a changed value of an environment variable, such as a rotated token, changes the key
	 * as well.
	 */
	public function cacheKey(Settings $settings, string $name): string
	{
		return "lettermint.{$name}." . hash('sha256', serialize([$this->version, $settings]));
	}

	/**
	 * Checks the settings with Lettermint: the project token, and with a team token the results of the team API, against
	 * all sites, also the disabled ones, as they may still send emails.
	 *
	 * @param bool $withStats Whether the statistics of the project are loaded as well, which the health report leaves out.
	 * @return array{0:?TokenStatus,1:?TeamApiData,2:CheckResult}
	 */
	private function check(Settings $settings, bool $withStats): array
	{
		$api = $settings->teamToken !== null ? $this->api->teamApi($settings->teamToken, $settings->projectId, $withStats) : null;
		$tokenStatus = $this->api->tokenStatus($settings->token);

		return [$tokenStatus, $api, Checks::build($settings, $tokenStatus, $api, Craft::$app->getSites()->getAllSites(true))];
	}
}
