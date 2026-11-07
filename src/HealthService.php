<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\helpers\App;
use DateTimeImmutable;
use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\data\Sections;
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
		$teamToken = $settings->teamToken;
		$sections = $teamToken !== null ? Plugin::instance()->getTeam()->api($teamToken)->health($settings->projectId) : null;
		$checks = $this->checks($settings, $sections);

		return new HealthReport(
			checks: $checks->all(),
			usage: $sections?->usageSummary(),
			date: new DateTimeImmutable(),
		);
	}

	/**
	 * The checks of the settings, against the sections of the team API, the system email address and all sites, also
	 * the disabled ones, as they may still send emails.
	 *
	 * @param Sections|null $sections The sections of the team API, or null without a team token.
	 */
	public function checks(Settings $settings, ?Sections $sections): Checks
	{
		return new Checks(
			$settings,
			$this->tokenStatus($settings->token),
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
