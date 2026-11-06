<?php

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Checks whether emails can be sent with Lettermint, for monitoring through the health endpoint (Pro edition).
 *
 * The project API token is always checked. With a team API token, the usage of the plan and the checks of the
 * settings against the project are added.
 *
 * The report is cached for a minute, so frequent monitoring does not run into the rate limits of Lettermint.
 */
final class Health
{
	/**
	 * The route of the endpoint, as an action of the plugin.
	 */
	public const ACTION = 'lettermint/health';

	public const OK = Check::OK;
	public const WARNING = Check::WARNING;
	public const ERROR = Check::ERROR;

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
	 *
	 * @return array{status:string,date:string,checks:list<array{id:string,status:string,text:string}>,usage:?array{used:int,included:?int,share:?float}}
	 */
	public static function report(Adapter $adapter): array
	{
		// Other settings, or another system email address, are checked again right away.
		return Craft::$app->getCache()->getOrSet($adapter->cacheKey('health'), static fn () => self::check($adapter), self::CACHE_DURATION);
	}

	/**
	 * Checks the adapter with Lettermint.
	 *
	 * @return array{status:string,date:string,checks:list<array{id:string,status:string,text:string}>,usage:?array{used:int,included:?int,share:?float}}
	 */
	public static function check(Adapter $adapter): array
	{
		$checks = [self::tokenCheck($adapter->checkToken())];
		$usage = null;

		if (null !== $teamToken = $adapter->getTeamToken()) {
			$projectId = $adapter->getProjectId();
			$sections = (new TeamApi($teamToken))->health($projectId);
			$checks[] = self::teamTokenCheck(Overview::teamTokenStatus($sections));

			if (!Overview::invalid($sections)) {
				$usage = Overview::usage($sections);

				if ($usage !== null) {
					$checks[] = self::usageCheck($usage);
				}

				$project = Overview::project($adapter, $projectId, $sections);

				if (null !== $check = self::projectCheck($project)) {
					$checks[] = $check;
				}

				array_push($checks, ...$project['checks']);
			}
		}

		return [
			'status' => Check::worst($checks),
			'date' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'checks' => $checks,
			'usage' => $usage !== null ? [
				'used' => $usage['used'],
				'included' => $usage['included'],
				'share' => $usage['share'],
			] : null,
		];
	}

	/**
	 * A project that could not be loaded is a warning, as emails are still sent.
	 *
	 * @param array{id:?string,error:?string} $project
	 * @return array{id:string,status:string,text:string}|null
	 */
	private static function projectCheck(array $project): ?array
	{
		return match ($project['error']) {
			null => null,
			TeamApi::ERROR_MISSING => Check::create('project', self::WARNING, 'The project “{project}” is not a project of the team.', ['project' => $project['id']]),
			default => Check::create('project', self::WARNING, 'The project could not be loaded from Lettermint.'),
		};
	}

	/**
	 * Without an accepted project token, no emails are sent.
	 *
	 * @return array{id:string,status:string,text:string}
	 */
	private static function tokenCheck(?TokenStatus $status): array
	{
		return match ($status) {
			TokenStatus::Valid => Check::create('token', self::OK, 'Lettermint accepts the project API token.'),
			TokenStatus::Invalid => Check::create('token', self::ERROR, 'Lettermint rejects the project API token.'),
			TokenStatus::Unknown => Check::create('token', self::ERROR, 'Lettermint could not be reached.'),
			null => Check::create('token', self::ERROR, 'The project API token is not set.'),
		};
	}

	/**
	 * The team token is not needed for sending, so its problems are warnings.
	 *
	 * @return array{id:string,status:string,text:string}
	 */
	private static function teamTokenCheck(?TokenStatus $status): array
	{
		return match ($status) {
			TokenStatus::Valid => Check::create('teamToken', self::OK, 'Lettermint accepts the team API token.'),
			TokenStatus::Invalid => Check::create('teamToken', self::WARNING, 'Lettermint rejects the team API token.'),
			TokenStatus::Unknown, null => Check::create('teamToken', self::WARNING, 'The team could not be loaded from Lettermint.'),
		};
	}

	/**
	 * @param array{used:int,included:?int,share:?float} $usage
	 * @return array{id:string,status:string,text:string}
	 */
	private static function usageCheck(array $usage): array
	{
		$params = ['used' => $usage['used'], 'included' => $usage['included']];

		return match (true) {
			$usage['share'] === null => Check::create('usage', self::OK, '{used} emails were sent this period.', $params),
			$usage['share'] >= 1 => Check::create('usage', self::WARNING, 'The monthly volume of the plan is used up, with {used} of {included} emails.', $params),
			$usage['share'] >= self::USAGE_WARNING => Check::create('usage', self::WARNING, 'The monthly volume of the plan is nearly used up, with {used} of {included} emails.', $params),
			default => Check::create('usage', self::OK, '{used} of {included} emails of the plan were sent this period.', $params),
		};
	}
}
