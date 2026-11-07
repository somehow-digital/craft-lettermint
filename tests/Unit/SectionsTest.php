<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\data\Period;
use SomehowDigital\Craft\Lettermint\data\Project;
use SomehowDigital\Craft\Lettermint\data\Section;
use SomehowDigital\Craft\Lettermint\data\SectionError;
use SomehowDigital\Craft\Lettermint\data\Sections;
use SomehowDigital\Craft\Lettermint\data\Team;
use SomehowDigital\Craft\Lettermint\data\TokenStatus;
use SomehowDigital\Craft\Lettermint\data\Usage;
use SomehowDigital\Craft\Lettermint\data\UsageSummary;

/** The time that the usage is taken at, and the period around it. */
const USAGE_NOW = '2026-11-06T12:00:00+00:00';
const PERIOD_START = '2026-11-01T00:00:00Z';
const PERIOD_END = '2026-12-01T00:00:00Z';

/**
 * The usage of the current period and the included volume of the team, taken at `USAGE_NOW`.
 */
function sectionsUsage(Period $period, ?int $included = 1000): ?UsageSummary
{
	return (new Sections(
		team: Section::loaded(new Team(includedVolume: $included)),
		usage: Section::loaded(new Usage($period)),
	))->usageSummary(new DateTimeImmutable(USAGE_NOW));
}

/**
 * The dates of a period that are given with the usage, as `[start, end]`.
 *
 * @return array{?string,?string}
 */
function sectionsDates(string $start, string $end): array
{
	$usage = sectionsUsage(new Period(1, $start, $end));

	expect($usage)->not->toBeNull();
	assert($usage instanceof UsageSummary);

	return [$usage->start, $usage->end];
}

describe('requested', function (): void {
	it('lists the sections that were requested', function (): void {
		$team = Section::loaded(new Team());
		$usage = Section::failed(SectionError::Forbidden);
		$project = Section::loaded(new Project('project-1'));
		$domains = Section::loaded([]);
		$stats = Section::failed(SectionError::Unavailable);

		expect((new Sections($team, $usage, $project, $domains, $stats))->requested())->toBe([$team, $usage, $project, $domains, $stats]);
		expect((new Sections($team, $usage, stats: $stats))->requested())->toBe([$team, $usage, $stats]);
	});
});

describe('token status', function (): void {
	it('reports a token that Lettermint rejects for the team as invalid', function (): void {
		expect((new Sections(Section::failed(SectionError::Invalid), Section::loaded(new Usage())))->teamTokenStatus())
			->toBe(TokenStatus::Invalid);
	});

	it('reports a token that Lettermint rejects for another section as invalid', function (): void {
		expect((new Sections(Section::loaded(new Team()), Section::failed(SectionError::Invalid)))->teamTokenStatus())
			->toBe(TokenStatus::Invalid);
	});

	it('reports a token as valid when Lettermint accepts it', function (?SectionError $error, TokenStatus $expected): void {
		$team = $error instanceof SectionError ? Section::failed($error) : Section::loaded(new Team());

		expect((new Sections($team, Section::loaded(new Usage())))->teamTokenStatus())->toBe($expected);
	})->with([
		'team loaded' => [null, TokenStatus::Valid],
		'team forbidden' => [SectionError::Forbidden, TokenStatus::Valid],
		'team unavailable' => [SectionError::Unavailable, TokenStatus::Unknown],
	]);
});

describe('usage', function (): void {
	it('has no usage without a current period', function (Sections $sections): void {
		expect($sections->usageSummary())->toBeNull();
	})->with([
		'no period' => [new Sections(Section::loaded(new Team()), Section::loaded(new Usage()))],
		'usage not loaded' => [new Sections(Section::loaded(new Team()), Section::failed(SectionError::Forbidden))],
		'token rejected' => [new Sections(
			Section::loaded(new Team()),
			Section::loaded(new Usage(new Period(250))),
			Section::failed(SectionError::Invalid),
		)],
	]);

	it('compares the usage with the included volume', function (): void {
		expect(sectionsUsage(new Period(250)))->toEqual(new UsageSummary(250, 1000))
			->and(sectionsUsage(new Period(250))?->share)->toBe(0.25);
	});

	it('has no share without an included volume', function (): void {
		expect(sectionsUsage(new Period(250), null))->toEqual(new UsageSummary(250))
			->and(sectionsUsage(new Period(250), null)?->share)->toBeNull();
	});

	it('takes an included volume of one', function (): void {
		expect(sectionsUsage(new Period(1), 1)?->share)->toBe(1.0);
	});

	it('has no included volume when the team could not be loaded', function (): void {
		expect((new Sections(Section::failed(SectionError::Forbidden), Section::loaded(new Usage(new Period(250)))))->usageSummary())
			->toEqual(new UsageSummary(250));
	});

	it('shares the usage as a fraction, also when it divides evenly', function (): void {
		expect(sectionsUsage(new Period(0))?->share)->toBe(0.0);
		expect(sectionsUsage(new Period(2000))?->share)->toBe(2.0);
	});

	it('gives the dates of a period that contains the time', function (string $start, string $end, bool $dated): void {
		expect(sectionsDates($start, $end))->toBe($dated ? [$start, $end] : [null, null]);
	})->with([
		'within' => [PERIOD_START, PERIOD_END, true],
		'starting at the time' => [USAGE_NOW, PERIOD_END, true],
		'ending at the time' => ['2026-10-01T00:00:00Z', USAGE_NOW, false],
		'passed' => ['2026-10-01T00:00:00Z', PERIOD_START, false],
		'to come' => [PERIOD_END, '2027-01-01T00:00:00Z', false],
		'invalid start' => ['not a date', PERIOD_END, false],
		'invalid end' => [PERIOD_START, 'not a date', false],
	]);
});
