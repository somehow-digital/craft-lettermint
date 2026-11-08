<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\api;

use Lettermint\Types\DomainData;
use Lettermint\Types\DomainListData;
use Lettermint\Types\ProjectData;
use Lettermint\Types\RouteData;
use Lettermint\Types\StatsDailyData;
use Lettermint\Types\StatsData;
use Lettermint\Types\StatsInboundData;
use Lettermint\Types\StatsTotalsData;
use Lettermint\Types\TeamData;
use Lettermint\Types\TeamUsageDetailData;
use Lettermint\Types\TeamUsagePeriodData;
use SomehowDigital\Craft\Lettermint\models\Domain;
use SomehowDigital\Craft\Lettermint\models\Period;
use SomehowDigital\Craft\Lettermint\models\Project;
use SomehowDigital\Craft\Lettermint\models\Route;
use SomehowDigital\Craft\Lettermint\models\Stats;
use SomehowDigital\Craft\Lettermint\models\StatsDay;
use SomehowDigital\Craft\Lettermint\models\StatsTotals;
use SomehowDigital\Craft\Lettermint\models\Team;

/**
 * Maps the objects of the SDK of Lettermint to the models of the plugin, so only the api namespace knows the SDK.
 *
 * The SDK passes values of an unexpected shape through as they are, so fields that are missing or of another type are
 * read as empty.
 *
 * @internal
 */
final class Mapper
{
	/**
	 * The team of the team API token.
	 */
	public static function team(TeamData $team): Team
	{
		return new Team(
			name: self::string($team->get('name')),
			type: self::string($team->get('type')),
			plan: self::string($team->get('plan')),
			includedVolume: self::positive($team->get('included_volume')),
			verifiedAt: self::string($team->get('verified_at')),
			createdAt: self::string($team->get('created_at')),
		);
	}

	/**
	 * The current period of the usage of the team, if Lettermint sent one.
	 */
	public static function usage(TeamUsageDetailData $usage): ?Period
	{
		$period = $usage->get('current_period');

		return $period instanceof TeamUsagePeriodData ? self::period($period) : null;
	}

	/**
	 * A project, with its routes and domains.
	 */
	public static function project(ProjectData $project): Project
	{
		return new Project(
			id: self::string($project->get('id')),
			name: self::string($project->get('name')),
			deliveryMode: self::string($project->get('delivery_mode')),
			tokenLastUsedAt: self::string($project->get('token_last_used_at')),
			tokenLastUsedIp: self::string($project->get('token_last_used_ip')),
			routes: array_map(self::route(...), self::list($project->get('routes'), RouteData::class)),
			domains: array_map(self::domain(...), self::list($project->get('domains'), DomainData::class)),
		);
	}

	/**
	 * A route of a project.
	 */
	public static function route(RouteData $route): Route
	{
		return new Route(
			slug: self::string($route->get('slug')),
			type: self::string($route->get('route_type')),
			default: $route->get('is_default') === true,
		);
	}

	/**
	 * A domain, whose status only the list of domains has.
	 */
	public static function domain(DomainData|DomainListData $domain): Domain
	{
		return new Domain(
			id: self::string($domain->get('id')),
			domain: self::string($domain->get('domain')),
			status: self::string($domain->get('status')),
			statusChangedAt: self::string($domain->get('status_changed_at')),
		);
	}

	/**
	 * The domains of a list of domains, with their status.
	 *
	 * @return list<Domain>
	 */
	public static function domains(mixed $domains): array
	{
		return array_map(self::domain(...), self::list($domains, DomainListData::class));
	}

	/**
	 * The message statistics of a project, in total and per day.
	 */
	public static function stats(StatsData $stats): Stats
	{
		$totals = $stats->get('totals');

		return new Stats(
			from: self::string($stats->get('from')),
			to: self::string($stats->get('to')),
			totals: $totals instanceof StatsTotalsData ? self::statsTotals($totals) : new StatsTotals(),
			daily: array_map(self::statsDay(...), self::list($stats->get('daily'), StatsDailyData::class)),
		);
	}

	/**
	 * The emails of a project sent on a day.
	 */
	public static function statsDay(StatsDailyData $day): StatsDay
	{
		return new StatsDay(
			date: self::string($day->get('date')),
			sent: self::int($day->get('sent')) ?? 0,
		);
	}

	private static function period(TeamUsagePeriodData $period): Period
	{
		return new Period(
			used: self::int($period->get('usage')) ?? 0,
			start: self::string($period->get('period_start')),
			end: self::string($period->get('period_end')),
		);
	}

	private static function statsTotals(StatsTotalsData $totals): StatsTotals
	{
		$inbound = $totals->get('inbound');

		return new StatsTotals(
			sent: self::int($totals->get('sent')) ?? 0,
			delivered: self::int($totals->get('delivered')) ?? 0,
			hardBounced: self::int($totals->get('hard_bounced')) ?? 0,
			spamComplaints: self::int($totals->get('spam_complaints')) ?? 0,
			opened: self::int($totals->get('opened')),
			clicked: self::int($totals->get('clicked')),
			received: $inbound instanceof StatsInboundData ? self::int($inbound->get('received')) ?? 0 : 0,
		);
	}

	/**
	 * A string, or null when it is not one or empty.
	 */
	private static function string(mixed $value): ?string
	{
		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * A whole number, also one sent as a string, or null when it is not a number.
	 */
	private static function int(mixed $value): ?int
	{
		return is_numeric($value) ? (int) $value : null;
	}

	/**
	 * A whole number above zero, also one sent as a string, or null otherwise.
	 */
	private static function positive(mixed $value): ?int
	{
		return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
	}

	/**
	 * The items of a list that are objects of the class, or none when it is not a list.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return list<T>
	 */
	private static function list(mixed $value, string $class): array
	{
		return is_array($value) ? array_values(array_filter($value, static fn(mixed $item): bool => $item instanceof $class)) : [];
	}
}
