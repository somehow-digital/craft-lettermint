<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\Check;
use SomehowDigital\Craft\Lettermint\CheckStatus;

it('takes the worst status', function (CheckStatus $expected, CheckStatus ...$statuses): void {
	expect(CheckStatus::worst(...$statuses))->toBe($expected);
})->with([
	// The expected status, then the statuses.
	'none' => [CheckStatus::Ok],
	'ok' => [CheckStatus::Ok, CheckStatus::Ok, CheckStatus::Ok],
	'warning after ok' => [CheckStatus::Warning, CheckStatus::Ok, CheckStatus::Warning],
	'ok after warning' => [CheckStatus::Warning, CheckStatus::Warning, CheckStatus::Ok],
	'error after warning' => [CheckStatus::Error, CheckStatus::Warning, CheckStatus::Error],
	'warning after error' => [CheckStatus::Error, CheckStatus::Error, CheckStatus::Warning],
]);

it('takes the worst status of the checks', function (): void {
	expect(Check::worst([Check::ok('a', 'A'), Check::error('b', 'B'), Check::warning('c', 'C')]))->toBe(CheckStatus::Error);
});

it('serializes to the JSON of the health endpoint', function (): void {
	expect(json_encode(Check::warning('usage', '{used} emails were sent this period.', ['used' => 5]), JSON_THROW_ON_ERROR))
		->toBe('{"id":"usage","status":"warning","text":"5 emails were sent this period."}');
});
