<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\data\Domain;
use SomehowDigital\Craft\Lettermint\data\Period;
use SomehowDigital\Craft\Lettermint\data\Project;
use SomehowDigital\Craft\Lettermint\data\Section;
use SomehowDigital\Craft\Lettermint\data\Team;
use SomehowDigital\Craft\Lettermint\data\Usage;
use SomehowDigital\Craft\Lettermint\Overview;
use SomehowDigital\Craft\Lettermint\SectionError;
use SomehowDigital\Craft\Lettermint\TokenStatus;

/** The time that the usage is taken at, and the period around it. */
const OVERVIEW_NOW = '2026-11-06T12:00:00+00:00';
const PERIOD_START = '2026-11-01T00:00:00Z';
const PERIOD_END = '2026-12-01T00:00:00Z';

/**
 * The usage of the current period and the included volume of the team, taken at `OVERVIEW_NOW`.
 *
 * @return array{used:int,included:?int,share:?float,start:?string,end:?string}|null
 */
function overviewUsage(Period $period, ?int $included = 1000): ?array
{
	return Overview::usage([
		'team' => Section::loaded(new Team(includedVolume: $included)),
		'usage' => Section::loaded(new Usage($period)),
	], new DateTimeImmutable(OVERVIEW_NOW));
}

/**
 * The dates of a period that are given with the usage, as `[start, end]`.
 *
 * @return array{?string,?string}
 */
function overviewDates(string $start, string $end): array
{
	$usage = overviewUsage(new Period(1, $start, $end));

	expect($usage)->not->toBeNull();
	assert($usage !== null);

	return [$usage['start'], $usage['end']];
}

describe('token status', function (): void {
	it('has no status without sections', function (): void {
		expect(Overview::teamTokenStatus(null))->toBeNull();
	});

	it('reports a token that Lettermint rejects for the team as invalid', function (): void {
		expect(Overview::teamTokenStatus(['team' => Section::failed(SectionError::Invalid)]))->toBe(TokenStatus::Invalid);
	});

	it('reports a token that Lettermint rejects for another section as invalid', function (): void {
		expect(Overview::teamTokenStatus([
			'team' => Section::loaded(new Team()),
			'project' => null,
			'usage' => Section::failed(SectionError::Invalid),
		]))->toBe(TokenStatus::Invalid);
	});
});

describe('usage', function (): void {
	it('has no usage without a current period', function (?array $sections): void {
		/** @var array{team:Section<Team>,usage:Section<Usage>}|null $sections */
		expect(Overview::usage($sections))->toBeNull();
	})->with([
		'no sections' => [null],
		'no period' => [['team' => Section::loaded(new Team()), 'usage' => Section::loaded(new Usage())]],
		'usage not loaded' => [['team' => Section::loaded(new Team()), 'usage' => Section::failed(SectionError::Forbidden)]],
		'token rejected' => [[
			'team' => Section::loaded(new Team()),
			'usage' => Section::loaded(new Usage(new Period(250))),
			'project' => Section::failed(SectionError::Invalid),
		]],
	]);

	it('compares the usage with the included volume', function (): void {
		expect(overviewUsage(new Period(250)))->toMatchArray(['used' => 250, 'included' => 1000, 'share' => 0.25]);
	});

	it('has no share without an included volume', function (): void {
		expect(overviewUsage(new Period(250), null))->toMatchArray(['included' => null, 'share' => null]);
	});

	it('takes an included volume of one', function (): void {
		expect(overviewUsage(new Period(1), 1))->toMatchArray(['included' => 1, 'share' => 1.0]);
	});

	it('has no included volume when the team could not be loaded', function (): void {
		expect(Overview::usage(['team' => Section::failed(SectionError::Forbidden), 'usage' => Section::loaded(new Usage(new Period(250)))]))
			->toMatchArray(['used' => 250, 'included' => null, 'share' => null]);
	});

	it('shares the usage as a fraction, also when it divides evenly', function (): void {
		expect(assertArray(overviewUsage(new Period(0)))['share'])->toBe(0.0);
		expect(assertArray(overviewUsage(new Period(2000)))['share'])->toBe(2.0);
	});

	it('gives the dates of a period that contains the time', function (string $start, string $end, bool $dated): void {
		expect(overviewDates($start, $end))->toBe($dated ? [$start, $end] : [null, null]);
	})->with([
		'within' => [PERIOD_START, PERIOD_END, true],
		'starting at the time' => [OVERVIEW_NOW, PERIOD_END, true],
		'ending at the time' => ['2026-10-01T00:00:00Z', OVERVIEW_NOW, false],
		'passed' => ['2026-10-01T00:00:00Z', PERIOD_START, false],
		'to come' => [PERIOD_END, '2027-01-01T00:00:00Z', false],
		'invalid start' => ['not a date', PERIOD_END, false],
		'invalid end' => [PERIOD_START, 'not a date', false],
	]);
});

describe('project', function (): void {
	it('lists the domains of the project by name, with the status of the listed domains', function (): void {
		$project = Overview::project('project-1', [
			'project' => Section::loaded(new Project(id: 'project-1', domains: [
				new Domain('domain-2', 'b.acme.test'),
				new Domain('domain-1', 'a.acme.test', statusChangedAt: '2026-01-01'),
				new Domain(null, 'c.acme.test'),
				new Domain('domain-3'),
				new Domain('domain-4', '1.acme.test'),
			])),
			'domains' => Section::loaded([
				new Domain('domain-1', 'a.acme.test', 'verified', '2026-02-01'),
				new Domain('domain-2', 'b.acme.test', 'pending'),
				// Listed domains without an ID match none of the project.
				new Domain(null, 'c.acme.test', 'failed_verification'),
			]),
		]);

		// Domains without a name come first.
		expect($project['domains'])->toEqual([
			new Domain('domain-3'),
			new Domain('domain-4', '1.acme.test'),
			new Domain('domain-1', 'a.acme.test', 'verified', '2026-01-01'),
			new Domain('domain-2', 'b.acme.test', 'pending'),
			new Domain(null, 'c.acme.test'),
		]);
		expect(assertInstance($project['data'], Project::class)->domains)->toHaveCount(5);
	});

	it('has no domains without a project', function (?Section $section): void {
		$project = Overview::project('project-1', ['project' => $section, 'domains' => null]);

		expect($project)->toBe(['id' => 'project-1', 'data' => null, 'error' => $section?->error, 'domains' => []]);
	})->with([
		'not requested' => [null],
		'not loaded' => [Section::failed(SectionError::Missing)],
	]);

	it('keeps the domains of the project without the listed domains', function (): void {
		$project = Overview::project('project-1', [
			'project' => Section::loaded(new Project(id: 'project-1', domains: [new Domain('domain-1', 'acme.test')])),
			'domains' => Section::failed(SectionError::Unavailable),
		]);

		expect($project['domains'])->toEqual([new Domain('domain-1', 'acme.test')]);
	});
});
