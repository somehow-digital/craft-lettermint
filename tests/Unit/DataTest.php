<?php

declare(strict_types=1);

use Lettermint\Types\DomainData;
use Lettermint\Types\DomainListData;
use Lettermint\Types\ProjectData;
use Lettermint\Types\RouteData;
use Lettermint\Types\StatsData;
use Lettermint\Types\TeamData;
use Lettermint\Types\TeamUsageDetailData;
use SomehowDigital\Craft\Lettermint\api\Mapper;
use SomehowDigital\Craft\Lettermint\models\Domain;
use SomehowDigital\Craft\Lettermint\models\Period;
use SomehowDigital\Craft\Lettermint\models\Project;
use SomehowDigital\Craft\Lettermint\models\Route;
use SomehowDigital\Craft\Lettermint\models\Stats;
use SomehowDigital\Craft\Lettermint\models\StatsDay;
use SomehowDigital\Craft\Lettermint\models\StatsTotals;
use SomehowDigital\Craft\Lettermint\models\UsageSummary;

describe('team', function (): void {
	it('reads an included volume above zero, also one sent as a string, as a whole number', function (mixed $included, ?int $expected): void {
		expect(Mapper::team(TeamData::from(['included_volume' => $included]))->includedVolume)->toBe($expected);
	})->with([
		'number' => [1000, 1000],
		'string' => ['1000', 1000],
		'one' => [1, 1],
		'fraction' => ['1.5', 1],
		'zero' => [0, null],
		'below one' => ['0.5', null],
		'negative' => [-5, null],
		'not a number' => ['n/a', null],
		'none' => [null, null],
	]);

	it('leaves out strings that are empty or not strings', function (): void {
		$team = Mapper::team(TeamData::from(['name' => '', 'plan' => 5, 'type' => ['business'], 'created_at' => '2026-01-01']));

		expect([$team->name, $team->plan, $team->type, $team->createdAt])->toBe([null, null, null, '2026-01-01']);
	});
});

describe('usage', function (): void {
	it('has no current period when Lettermint sends none', function (mixed $period): void {
		expect(Mapper::usage(TeamUsageDetailData::from(['current_period' => $period])))->toBeNull();
	})->with(['none' => [null], 'not an object' => ['2026-11'], 'a list' => [[['usage' => 1]]]]);

	it('counts a usage that is not a number as none', function (): void {
		expect(Mapper::usage(TeamUsageDetailData::from(['current_period' => ['usage' => 'n/a']])))->toEqual(new Period());
	});

	it('reads a usage sent as a string', function (): void {
		expect(Mapper::usage(TeamUsageDetailData::from(['current_period' => ['usage' => '250']]))?->used)->toBe(250);
	});
});

describe('usage summary', function (): void {
	it('serializes to the usage of the health endpoint, without the dates of the period', function (): void {
		expect(json_encode(new UsageSummary(250, 1000, '2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'), JSON_THROW_ON_ERROR))
			->toBe('{"used":250,"included":1000,"share":0.25}');
	});
});

describe('project', function (): void {
	it('leaves out routes and domains that are not objects', function (): void {
		$project = Mapper::project(ProjectData::from([
			'routes' => ['not a route', ['slug' => 'outgoing']],
			'domains' => [['domain' => 'acme.test'], 'not a domain'],
		]));

		// Reindexed without gaps.
		expect($project->routes)->toEqual([0 => new Route('outgoing')]);
		expect($project->domains)->toEqual([new Domain(domain: 'acme.test')]);
	});

	it('reads the settings of the project', function (): void {
		$project = Mapper::project(ProjectData::from([
			'smtp_enabled' => true,
			'redact_email_content' => false,
			'created_at' => '2026-01-15T10:00:00Z',
		]));

		expect([$project->smtpEnabled, $project->redactEmailContent, $project->createdAt])->toBe([true, false, '2026-01-15T10:00:00Z']);
	});

	it('only takes settings that are booleans', function (): void {
		$project = Mapper::project(ProjectData::from(['smtp_enabled' => 1, 'redact_email_content' => 'no']));

		expect([$project->smtpEnabled, $project->redactEmailContent])->toBe([null, null]);
	});

	it('is in sandbox mode when Lettermint says so', function (?string $mode, ?bool $sandbox): void {
		expect((new Project(deliveryMode: $mode))->isSandbox())->toBe($sandbox);
	})->with(['sandbox' => ['sandbox', true], 'live' => ['live', false], 'unknown' => [null, null]]);

	it('has no routes or domains when they are not lists', function (): void {
		expect(Mapper::project(ProjectData::from(['routes' => 'none', 'domains' => 5])))->toEqual(new Project());
	});
});

describe('route', function (): void {
	it('only takes slugs that are strings', function (): void {
		expect(Mapper::route(RouteData::from(['slug' => 5]))->slug)->toBeNull();
	});

	it('is only the default when Lettermint says so', function (mixed $default, bool $expected): void {
		expect(Mapper::route(RouteData::from(['is_default' => $default]))->default)->toBe($expected);
	})->with(['true' => [true, true], 'false' => [false, false], 'truthy' => [1, false], 'none' => [null, false]]);

	it('reads the tracking of the route settings', function (): void {
		$route = Mapper::route(RouteData::from(['settings' => ['track_opens' => true, 'track_clicks' => false]]));

		expect([$route->trackOpens, $route->trackClicks])->toBe([true, false]);
	});

	it('has no tracking without route settings or with other values', function (mixed $settings): void {
		$route = Mapper::route(RouteData::from(['settings' => $settings]));

		expect([$route->trackOpens, $route->trackClicks])->toBe([null, null]);
	})->with(['none' => [null], 'not an object' => ['on'], 'not booleans' => [['track_opens' => 1, 'track_clicks' => 'yes']]]);

	it('is outgoing unless it is inbound', function (?string $type, bool $outgoing): void {
		expect((new Route(type: $type))->outgoing())->toBe($outgoing);
	})->with(['transactional' => ['transactional', true], 'broadcast' => ['broadcast', true], 'none' => [null, true], 'inbound' => ['inbound', false]]);
});

describe('domain', function (): void {
	it('reads the status of a listed domain', function (): void {
		expect(Mapper::domain(DomainListData::from(['id' => 'domain-1', 'domain' => 'acme.test', 'status' => 'verified', 'status_changed_at' => '2026-02-01'])))
			->toEqual(new Domain('domain-1', 'acme.test', 'verified', '2026-02-01'));
	});

	it('has no status as a domain of a project', function (): void {
		expect(Mapper::domain(DomainData::from(['id' => 'domain-1', 'domain' => 'acme.test']))->status)->toBeNull();
	});
});

describe('stats', function (): void {
	it('reads the totals and the days', function (): void {
		$stats = Mapper::stats(StatsData::from([
			'from' => '2026-10-06',
			'to' => '2026-11-04',
			'totals' => [
				'sent' => '10',
				'delivered' => 9,
				'hard_bounced' => 1,
				'spam_complaints' => 0,
				'opened' => null,
				'clicked' => 3,
				'inbound' => ['received' => 4],
			],
			'daily' => [['date' => '2026-11-04', 'sent' => 10], ['date' => '2026-11-05'], 'not a day'],
		]));

		expect($stats)->toEqual(new Stats(
			'2026-10-06',
			'2026-11-04',
			new StatsTotals(10, 9, 1, 0, null, 3, 4),
			[new StatsDay('2026-11-04', 10), new StatsDay('2026-11-05')],
		));
	});

	it('has empty totals when Lettermint sends none', function (mixed $totals): void {
		expect(Mapper::stats(StatsData::from(['totals' => $totals]))->totals)->toEqual(new StatsTotals());
	})->with(['none' => [null], 'not an object' => ['none'], 'a list' => [[1, 2]], 'no fields' => [[]]]);

	it('has received none without inbound totals', function (mixed $inbound): void {
		expect(Mapper::stats(StatsData::from(['totals' => ['inbound' => $inbound]]))->totals->received)->toBe(0);
	})->with(['none' => [null], 'not an object' => [4], 'not a number' => [['received' => 'n/a']]]);
});
