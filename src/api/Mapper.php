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
use SomehowDigital\Craft\Lettermint\helpers\Field;
use SomehowDigital\Craft\Lettermint\models\Domain;
use SomehowDigital\Craft\Lettermint\models\Period;
use SomehowDigital\Craft\Lettermint\models\Project;
use SomehowDigital\Craft\Lettermint\models\Route;
use SomehowDigital\Craft\Lettermint\models\Stats;
use SomehowDigital\Craft\Lettermint\models\StatsDay;
use SomehowDigital\Craft\Lettermint\models\StatsTotals;
use SomehowDigital\Craft\Lettermint\models\Team;
use SomehowDigital\Craft\Lettermint\models\Usage;

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
			name: Field::string($team->get('name')),
			type: Field::string($team->get('type')),
			plan: Field::string($team->get('plan')),
			includedVolume: Field::positive($team->get('included_volume')),
			verifiedAt: Field::string($team->get('verified_at')),
			createdAt: Field::string($team->get('created_at')),
		);
	}

	/**
	 * The usage of the team, with its current period, if Lettermint sent one.
	 */
	public static function usage(TeamUsageDetailData $usage): Usage
	{
		$period = $usage->get('current_period');

		return new Usage(
			current: $period instanceof TeamUsagePeriodData ? self::period($period) : null,
		);
	}

	/**
	 * A project, with its routes and domains.
	 */
	public static function project(ProjectData $project): Project
	{
		return new Project(
			id: Field::string($project->get('id')),
			name: Field::string($project->get('name')),
			deliveryMode: Field::string($project->get('delivery_mode')),
			tokenLastUsedAt: Field::string($project->get('token_last_used_at')),
			tokenLastUsedIp: Field::string($project->get('token_last_used_ip')),
			routes: array_map(self::route(...), Field::list($project->get('routes'), RouteData::class)),
			domains: array_map(self::domain(...), Field::list($project->get('domains'), DomainData::class)),
		);
	}

	/**
	 * A route of a project.
	 */
	public static function route(RouteData $route): Route
	{
		return new Route(
			slug: Field::string($route->get('slug')),
			type: Field::string($route->get('route_type')),
			default: $route->get('is_default') === true,
		);
	}

	/**
	 * A domain, whose status only the list of domains has.
	 */
	public static function domain(DomainData|DomainListData $domain): Domain
	{
		return new Domain(
			id: Field::string($domain->get('id')),
			domain: Field::string($domain->get('domain')),
			status: Field::string($domain->get('status')),
			statusChangedAt: Field::string($domain->get('status_changed_at')),
		);
	}

	/**
	 * The message statistics of a project, in total and per day.
	 */
	public static function stats(StatsData $stats): Stats
	{
		$totals = $stats->get('totals');

		return new Stats(
			from: Field::string($stats->get('from')),
			to: Field::string($stats->get('to')),
			totals: $totals instanceof StatsTotalsData ? self::statsTotals($totals) : new StatsTotals(),
			daily: array_map(self::statsDay(...), Field::list($stats->get('daily'), StatsDailyData::class)),
		);
	}

	private static function period(TeamUsagePeriodData $period): Period
	{
		return new Period(
			used: Field::int($period->get('usage')) ?? 0,
			start: Field::string($period->get('period_start')),
			end: Field::string($period->get('period_end')),
		);
	}

	private static function statsTotals(StatsTotalsData $totals): StatsTotals
	{
		$inbound = $totals->get('inbound');

		return new StatsTotals(
			sent: Field::int($totals->get('sent')) ?? 0,
			delivered: Field::int($totals->get('delivered')) ?? 0,
			hardBounced: Field::int($totals->get('hard_bounced')) ?? 0,
			spamComplaints: Field::int($totals->get('spam_complaints')) ?? 0,
			opened: Field::int($totals->get('opened')),
			clicked: Field::int($totals->get('clicked')),
			received: $inbound instanceof StatsInboundData ? Field::int($inbound->get('received')) ?? 0 : 0,
		);
	}

	private static function statsDay(StatsDailyData $day): StatsDay
	{
		return new StatsDay(
			date: Field::string($day->get('date')),
			sent: Field::int($day->get('sent')) ?? 0,
		);
	}
}
