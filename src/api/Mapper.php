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
 * Maps SDK objects to plugin models, so only the api namespace knows the SDK.
 *
 * The SDK passes unexpected shapes through, so missing or mistyped fields are read as empty.
 *
 * @internal
 */
final class Mapper
{
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
	 * The current period, if Lettermint sent one.
	 */
	public static function usage(TeamUsageDetailData $usage): ?Period
	{
		$period = $usage->get('current_period');

		return $period instanceof TeamUsagePeriodData ? self::period($period) : null;
	}

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

	public static function route(RouteData $route): Route
	{
		return new Route(
			slug: self::string($route->get('slug')),
			type: self::string($route->get('route_type')),
			default: $route->get('is_default') === true,
		);
	}

	/**
	 * Only the domain list includes the status.
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
	 * @return list<Domain>
	 */
	public static function domains(mixed $domains): array
	{
		return array_map(self::domain(...), self::list($domains, DomainListData::class));
	}

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
	 * Non-empty string or null.
	 */
	private static function string(mixed $value): ?string
	{
		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * Integer, also from a numeric string, or null.
	 */
	private static function int(mixed $value): ?int
	{
		return is_numeric($value) ? (int) $value : null;
	}

	/**
	 * Positive integer, also from a numeric string, or null.
	 */
	private static function positive(mixed $value): ?int
	{
		return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
	}

	/**
	 * Items of the class, or none when the value isn't a list.
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
