<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use Craft;
use DateTimeImmutable;
use SomehowDigital\Craft\Lettermint\helpers\MailSettings;
use SomehowDigital\Craft\Lettermint\models\HealthReport;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\Plugin;
use yii\base\Component;

/**
 * Checks whether emails can be sent with Lettermint, for monitoring through the health endpoint (Pro edition).
 *
 * The checks are built by the `checks` component, which the utility uses as well.
 *
 * The report is cached for a minute, so frequent monitoring does not run into the rate limits of Lettermint.
 *
 * The plugin registers it as its `health` component, which projects can configure or replace through the plugin
 * configs of Craft.
 */
class HealthService extends Component
{
	/**
	 * The route of the endpoint, as an action of the plugin.
	 */
	public const ACTION = 'lettermint/health';

	/**
	 * The seconds a report is cached for.
	 */
	public const CACHE_DURATION = 60;

	/**
	 * The URL of the endpoint on the primary site, for monitoring.
	 */
	public static function url(): string
	{
		return Plugin::actionUrl(self::ACTION);
	}

	/**
	 * The report of the settings, from the cache when they were checked within the last minute.
	 */
	public function report(Settings $settings): HealthReport
	{
		// Other settings, or another system email address, are checked again right away.
		return Craft::$app->getCache()?->getOrSet($this->cacheKey($settings), fn(): HealthReport => $this->check($settings), self::CACHE_DURATION)
			?? $this->check($settings);
	}

	/**
	 * The key of the report cached for settings.
	 */
	private function cacheKey(Settings $settings): string
	{
		return MailSettings::cacheKey($settings, 'health');
	}

	/**
	 * Checks the settings with Lettermint.
	 */
	public function check(Settings $settings): HealthReport
	{
		$result = Plugin::instance()->getChecks()->load($settings);

		return new HealthReport(
			checks: $result->all(),
			usage: $result->sections?->usageSummary(),
			date: new DateTimeImmutable(),
		);
	}
}
