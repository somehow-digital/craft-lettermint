<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\helpers\App;
use DateTimeImmutable;
use SensitiveParameter;
use yii\base\Component;

/**
 * Checks whether emails can be sent with Lettermint, for monitoring through the health endpoint (Pro edition).
 *
 * The checks are those of `Checks`, which the utility shows as well, and are built here for both, so projects can
 * change how the project token is checked by replacing `tokenStatus()`.
 *
 * The report is cached for a minute, so frequent monitoring does not run into the rate limits of Lettermint.
 *
 * The plugin registers it as its `health` component, which projects can configure or replace through the plugin
 * configs of Craft.
 *
 * @phpstan-import-type HealthSections from TeamApi
 * @phpstan-import-type Sections from TeamApi
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
		return Craft::$app->getCache()?->getOrSet($this->cacheKey($adapter), fn(): HealthReport => $this->check($adapter), self::CACHE_DURATION)
			?? $this->check($adapter);
	}

	/**
	 * The key of the report cached for an adapter.
	 */
	private function cacheKey(Adapter $adapter): string
	{
		return MailSettings::cacheKey($adapter, 'health');
	}

	/**
	 * Checks the adapter with Lettermint.
	 */
	public function check(Adapter $adapter): HealthReport
	{
		$teamToken = $adapter->getTeamToken();
		$sections = $teamToken !== null ? Plugin::instance()->getTeam()->api($teamToken)->health($adapter->getProjectId()) : null;
		$checks = $this->checks($adapter, $sections);
		$usage = Overview::usage($sections);

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

	/**
	 * The checks of an adapter, against the sections of the team API, the system email address and all sites, also
	 * the disabled ones, as they may still send emails.
	 *
	 * @param HealthSections|Sections|null $sections
	 *     The sections of the team API, or null without a team token.
	 */
	public function checks(Adapter $adapter, ?array $sections): Checks
	{
		return new Checks(
			$adapter,
			$this->tokenStatus($adapter->getToken()),
			$sections,
			Env::value(App::mailSettings()->fromEmail),
			Craft::$app->getSites()->getAllSites(true),
		);
	}

	/**
	 * The status of a project token, checked with Lettermint, or null when there is no token to check.
	 */
	public function tokenStatus(#[SensitiveParameter] ?string $token): ?TokenStatus
	{
		return TokenChecker::check($token);
	}
}
