<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use DateTimeImmutable;
use yii\base\Component;

/**
 * Checks whether emails can be sent with Lettermint, for monitoring through the health endpoint (Pro edition).
 *
 * The project API token is always checked. With a team API token, the usage of the plan and the checks of the
 * settings against the project are added.
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
	 * The share of the monthly volume of the plan from which the usage is a warning.
	 */
	public const USAGE_WARNING = 0.9;

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
		$tokenCheck = $this->tokenCheck(TokenChecker::check($adapter->getToken()));
		[$teamChecks, $usage] = $this->teamChecks($adapter);

		return new HealthReport(
			checks: [$tokenCheck, ...$teamChecks],
			usage: $usage !== null ? [
				'used' => $usage['used'],
				'included' => $usage['included'],
				'share' => $usage['share'],
			] : null,
			date: new DateTimeImmutable(),
		);
	}

	/**
	 * Checks the team token, the usage and the project, with the usage, when the adapter has a team token.
	 *
	 * @return array{list<Check>,?array{used:int,included:?int,share:?float,start:?string,end:?string}}
	 */
	private function teamChecks(Adapter $adapter): array
	{
		if (null === $teamToken = $adapter->getTeamToken()) {
			return [[], null];
		}

		$projectId = $adapter->getProjectId();
		$sections = Plugin::instance()->getTeam()->api($teamToken)->health($projectId);
		$checks = [$this->teamTokenCheck(Overview::teamTokenStatus($sections))];

		if (Overview::invalid($sections)) {
			return [$checks, null];
		}

		$usage = Overview::usage($sections);

		if ($usage !== null) {
			$checks[] = $this->usageCheck($usage);
		}

		$project = Overview::project($adapter, $projectId, $sections);

		if (($check = $this->projectCheck($project)) instanceof Check) {
			$checks[] = $check;
		}

		return [[...$checks, ...$project['checks']], $usage];
	}

	/**
	 * A project that could not be loaded is a warning, as emails are still sent.
	 *
	 * @param array{id:?string,error:?string,...} $project
	 */
	private function projectCheck(array $project): ?Check
	{
		return match ($project['error']) {
			null => null,
			TeamApi::ERROR_MISSING => Check::warning('project', 'The project “{project}” is not a project of the team.', ['project' => $project['id']]),
			default => Check::warning('project', 'The project could not be loaded from Lettermint.'),
		};
	}

	/**
	 * Without an accepted project token, no emails are sent.
	 */
	private function tokenCheck(?TokenStatus $status): Check
	{
		return match ($status) {
			TokenStatus::Valid => Check::ok('token', 'Lettermint accepts the project API token.'),
			TokenStatus::Invalid => Check::error('token', 'Lettermint rejects the project API token.'),
			TokenStatus::Unknown => Check::error('token', 'Lettermint could not be reached.'),
			null => Check::error('token', 'The project API token is not set.'),
		};
	}

	/**
	 * The team token is not needed for sending, so its problems are warnings.
	 */
	private function teamTokenCheck(?TokenStatus $status): Check
	{
		return match ($status) {
			TokenStatus::Valid => Check::ok('teamToken', 'Lettermint accepts the team API token.'),
			TokenStatus::Invalid => Check::warning('teamToken', 'Lettermint rejects the team API token.'),
			TokenStatus::Unknown, null => Check::warning('teamToken', 'The team could not be loaded from Lettermint.'),
		};
	}

	/**
	 * @param array{used:int,included:?int,share:?float,...} $usage
	 */
	private function usageCheck(array $usage): Check
	{
		$params = ['used' => $usage['used'], 'included' => $usage['included']];

		return match (true) {
			$usage['share'] === null => Check::ok('usage', '{used} emails were sent this period.', $params),
			$usage['share'] >= 1 => Check::warning('usage', 'The monthly volume of the plan is used up, with {used} of {included} emails.', $params),
			$usage['share'] >= self::USAGE_WARNING => Check::warning('usage', 'The monthly volume of the plan is nearly used up, with {used} of {included} emails.', $params),
			default => Check::ok('usage', '{used} of {included} emails of the plan were sent this period.', $params),
		};
	}
}
