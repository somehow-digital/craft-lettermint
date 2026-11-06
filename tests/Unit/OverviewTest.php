<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Overview;
use SomehowDigital\Craft\Lettermint\TokenStatus;

/** The time that the usage is taken at, and the period around it. */
const OVERVIEW_NOW = '2026-11-06T12:00:00+00:00';
const PERIOD_START = '2026-11-01T00:00:00Z';
const PERIOD_END = '2026-12-01T00:00:00Z';

/**
 * The usage of the current period and the included volume of the team, taken at `OVERVIEW_NOW`.
 *
 * @param array<string,mixed> $period
 * @return array{used:int,included:?int,share:?float,start:?string,end:?string}|null
 */
function overviewUsage(array $period, mixed $included = 1000): ?array
{
	return Overview::usage([
		'team' => ['data' => ['included_volume' => $included], 'error' => null],
		'usage' => ['data' => ['current_period' => $period], 'error' => null],
	], new DateTimeImmutable(OVERVIEW_NOW));
}

/**
 * The dates of a period that are given with the usage, as `[start, end]`.
 *
 * @return array{?string,?string}
 */
function overviewDates(string $start, string $end): array
{
	$usage = overviewUsage(['usage' => 1, 'period_start' => $start, 'period_end' => $end]);

	expect($usage)->not->toBeNull();
	assert($usage !== null);

	return [$usage['start'], $usage['end']];
}

describe('token status', function (): void {
	it('has no status without sections', function (): void {
		expect(Overview::teamTokenStatus(null))->toBeNull();
	});

	it('reports a token that Lettermint rejects for the team as invalid', function (): void {
		expect(Overview::teamTokenStatus(['team' => ['data' => null, 'error' => 'invalid']]))->toBe(TokenStatus::Invalid);
	});

	it('reports a token that Lettermint rejects for another section as invalid', function (): void {
		expect(Overview::teamTokenStatus([
			'team' => ['data' => [], 'error' => null],
			'project' => null,
			'usage' => ['data' => null, 'error' => 'invalid'],
		]))->toBe(TokenStatus::Invalid);
	});
});

describe('usage', function (): void {
	it('has no usage without a current period', function (?array $sections): void {
		/** @var array{team:array{data:?array<mixed>,error:?string},usage:array{data:?array<mixed>,error:?string}}|null $sections */
		expect(Overview::usage($sections))->toBeNull();
	})->with([
		'no sections' => [null],
		'no period' => [['team' => ['data' => [], 'error' => null], 'usage' => ['data' => [], 'error' => null]]],
		'usage not loaded' => [['team' => ['data' => [], 'error' => null], 'usage' => ['data' => null, 'error' => 'forbidden']]],
	]);

	it('reads numbers that are sent as strings', function (): void {
		$usage = assertArray(overviewUsage(['usage' => '250'], '1000'));

		expect($usage['used'])->toBe(250);
		expect($usage['included'])->toBe(1000);
		expect($usage['share'])->toBe(0.25);
	});

	it('counts a usage that is not a number as none', function (): void {
		expect(assertArray(overviewUsage(['usage' => 'n/a']))['used'])->toBe(0);
	});

	it('has no included volume without a positive one', function (mixed $included): void {
		expect(overviewUsage(['usage' => 250], $included))->toMatchArray(['included' => null, 'share' => null]);
	})->with(['zero' => [0], 'negative' => [-5], 'below one' => ['0.5'], 'not a number' => ['n/a'], 'none' => [null]]);

	it('takes an included volume of one, as a whole number', function (): void {
		$usage = assertArray(overviewUsage(['usage' => 1], '1.5'));

		expect($usage['included'])->toBe(1);
		expect($usage['share'])->toBe(1.0);
	});

	it('shares the usage as a fraction, also when it divides evenly', function (): void {
		expect(assertArray(overviewUsage(['usage' => 0]))['share'])->toBe(0.0);
		expect(assertArray(overviewUsage(['usage' => 2000]))['share'])->toBe(2.0);
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
		$project = Overview::project(new Adapter(['token' => 'secret']), 'project-1', [
			'project' => ['data' => ['domains' => [
				['id' => 'domain-2', 'domain' => 'b.acme.test'],
				['id' => 'domain-1', 'domain' => 'a.acme.test'],
				['id' => '', 'domain' => 'c.acme.test'],
				['id' => 'domain-3'],
				['id' => 'domain-4', 'domain' => '1.acme.test'],
				'not a domain',
			]], 'error' => null],
			'domains' => ['data' => ['data' => [
				['id' => 'domain-1', 'domain' => 'a.acme.test', 'status' => 'verified'],
				['id' => 'domain-2', 'domain' => 'b.acme.test', 'status' => 'pending'],
				// Listed domains without an ID match none of the project.
				['domain' => 'c.acme.test', 'status' => 'failed_verification'],
				'not a domain',
			]], 'error' => null],
		]);

		// Domains without a name come first.
		expect($project['domains'])->toBe([
			['id' => 'domain-3'],
			['id' => 'domain-4', 'domain' => '1.acme.test'],
			['id' => 'domain-1', 'domain' => 'a.acme.test', 'status' => 'verified'],
			['id' => 'domain-2', 'domain' => 'b.acme.test', 'status' => 'pending'],
			['id' => '', 'domain' => 'c.acme.test'],
		]);
	});
});
