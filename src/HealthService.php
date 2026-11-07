<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use DateTimeImmutable;
use yii\base\Component;

/**
 * Checks whether emails can be sent with Lettermint, for monitoring through the health endpoint (Pro edition).
 *
 * The checks are those of `Checks`, which the utility shows as well.
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
	 * The report of the adapter, from the cache when it was checked within the last minute.
	 */
	public function report(Adapter $adapter): HealthReport
	{
		// Other settings, or another system email address, are checked again right away.
		return Craft::$app->getCache()?->getOrSet(MailSettings::cacheKey($adapter, 'health'), fn(): HealthReport => $this->check($adapter), self::CACHE_DURATION)
			?? $this->check($adapter);
	}

	/**
	 * Checks the adapter with Lettermint.
	 */
	public function check(Adapter $adapter): HealthReport
	{
		$tokenStatus = TokenChecker::check($adapter->getToken());
		$teamToken = $adapter->getTeamToken();
		$sections = $teamToken !== null ? Plugin::instance()->getTeam()->api($teamToken)->health($adapter->getProjectId()) : null;
		$checks = new Checks($adapter, $tokenStatus, $sections);
		// Nothing more is loaded once Lettermint rejects the team token, so there is no usage either.
		$usage = Overview::invalid($sections) ? null : Overview::usage($sections);

		return new HealthReport(
			checks: $checks->all(),
			usage: $usage !== null ? [
				'used' => $usage['used'],
				'included' => $usage['included'],
				'share' => $usage['share'],
			] : null,
			date: new DateTimeImmutable(),
		);
	}
}
