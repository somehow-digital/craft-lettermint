<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\enums\ApiError;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\models\ApiResult;
use SomehowDigital\Craft\Lettermint\models\Period;
use SomehowDigital\Craft\Lettermint\models\Project;
use SomehowDigital\Craft\Lettermint\models\Team;
use SomehowDigital\Craft\Lettermint\models\TeamApiData;
use SomehowDigital\Craft\Lettermint\models\UsageSummary;

/** The time of the usage, and the period around it. */
const USAGE_NOW = '2026-11-06T12:00:00+00:00';
const PERIOD_START = '2026-11-01T00:00:00Z';
const PERIOD_END = '2026-12-01T00:00:00Z';

/**
 * Taken at `USAGE_NOW`.
 */
function apiUsage(Period $period, ?int $included = 1000): ?UsageSummary
{
	return (new TeamApiData(
		team: ApiResult::loaded(new Team(includedVolume: $included)),
		usage: ApiResult::loaded($period),
	))->usageSummary(new DateTimeImmutable(USAGE_NOW));
}

/**
 * The period dates kept in the usage, as `[start, end]`.
 *
 * @return array{?string,?string}
 */
function apiDates(string $start, string $end): array
{
	$usage = apiUsage(new Period(1, $start, $end));

	expect($usage)->not->toBeNull();
	assert($usage instanceof UsageSummary);

	return [$usage->start, $usage->end];
}

describe('requested', function (): void {
	it('lists the results that were requested', function (): void {
		$team = ApiResult::loaded(new Team());
		$usage = ApiResult::failed(ApiError::Forbidden);
		$project = ApiResult::loaded(new Project('project-1'));
		$stats = ApiResult::failed(ApiError::Unavailable);

		expect((new TeamApiData($team, $usage, $project, $stats))->requested())->toBe([$team, $usage, $project, $stats]);
		expect((new TeamApiData($team, $usage, stats: $stats))->requested())->toBe([$team, $usage, $stats]);
	});
});

describe('unavailable', function (): void {
	it('tells whether Lettermint could not be read for any of the results', function (?ApiResult $stats, bool $unavailable): void {
		$data = new TeamApiData(ApiResult::loaded(new Team()), ApiResult::failed(ApiError::Forbidden), stats: $stats);

		expect($data->unavailable())->toBe($unavailable);
	})->with([
		'loaded' => [null, false],
		'not requested' => [ApiResult::loaded(new Project()), false],
		'missing' => [ApiResult::failed(ApiError::Missing), false],
		'unavailable' => [ApiResult::failed(ApiError::Unavailable), true],
	]);
});

describe('token status', function (): void {
	it('reports a token that Lettermint rejects for the team as invalid', function (): void {
		expect((new TeamApiData(ApiResult::failed(ApiError::Invalid), ApiResult::loaded(null)))->teamTokenStatus())
			->toBe(TokenStatus::Invalid);
	});

	it('reports a token that Lettermint rejects for another result as invalid', function (): void {
		expect((new TeamApiData(ApiResult::loaded(new Team()), ApiResult::failed(ApiError::Invalid)))->teamTokenStatus())
			->toBe(TokenStatus::Invalid);
	});

	it('reports a token as valid when Lettermint accepts it', function (?ApiError $error, TokenStatus $expected): void {
		$team = $error instanceof ApiError ? ApiResult::failed($error) : ApiResult::loaded(new Team());

		expect((new TeamApiData($team, ApiResult::loaded(null)))->teamTokenStatus())->toBe($expected);
	})->with([
		'team loaded' => [null, TokenStatus::Valid],
		'team forbidden' => [ApiError::Forbidden, TokenStatus::Valid],
		'team unavailable' => [ApiError::Unavailable, TokenStatus::Unknown],
	]);
});

describe('usage', function (): void {
	it('has no usage without a current period', function (TeamApiData $api): void {
		expect($api->usageSummary())->toBeNull();
	})->with([
		'no period' => [new TeamApiData(ApiResult::loaded(new Team()), ApiResult::loaded(null))],
		'usage not loaded' => [new TeamApiData(ApiResult::loaded(new Team()), ApiResult::failed(ApiError::Forbidden))],
		'token rejected' => [new TeamApiData(
			ApiResult::loaded(new Team()),
			ApiResult::loaded(new Period(250)),
			ApiResult::failed(ApiError::Invalid),
		)],
	]);

	it('compares the usage with the included volume', function (): void {
		expect(apiUsage(new Period(250)))->toEqual(new UsageSummary(250, 1000))
			->and(apiUsage(new Period(250))?->share)->toBe(0.25);
	});

	it('has no share without an included volume', function (): void {
		expect(apiUsage(new Period(250), null))->toEqual(new UsageSummary(250))
			->and(apiUsage(new Period(250), null)?->share)->toBeNull();
	});

	it('takes an included volume of one', function (): void {
		expect(apiUsage(new Period(1), 1)?->share)->toBe(1.0);
	});

	it('has no included volume when the team could not be loaded', function (): void {
		expect((new TeamApiData(ApiResult::failed(ApiError::Forbidden), ApiResult::loaded(new Period(250))))->usageSummary())
			->toEqual(new UsageSummary(250));
	});

	it('shares the usage as a fraction, also when it divides evenly', function (): void {
		expect(apiUsage(new Period(0))?->share)->toBe(0.0);
		expect(apiUsage(new Period(2000))?->share)->toBe(2.0);
	});

	it('gives the dates of a period that contains the time', function (string $start, string $end, bool $dated): void {
		expect(apiDates($start, $end))->toBe($dated ? [$start, $end] : [null, null]);
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
