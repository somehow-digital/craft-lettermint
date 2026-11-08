<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\enums\CheckStatus;
use SomehowDigital\Craft\Lettermint\enums\CheckType;
use SomehowDigital\Craft\Lettermint\enums\Setup;
use SomehowDigital\Craft\Lettermint\models\Check;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\Stats;
use SomehowDigital\Craft\Lettermint\models\StatsDay;
use SomehowDigital\Craft\Lettermint\models\UtilityData;

describe('placeholder', function (): void {
	it('needs Lettermint as the transport', function (): void {
		$data = UtilityData::placeholder(null);

		expect($data->setup)->toBe(Setup::Transport);
		expect($data->token)->toBeNull();
	});

	it('needs a team token', function (): void {
		$data = UtilityData::placeholder(new Settings(token: 'secret'));

		expect($data->setup)->toBe(Setup::Token);
		expect($data->token?->status)->toBe(CheckStatus::Ok);
		expect($data->loaded())->toBeFalse();
	});

	it('has the team and its usage of the current period, without a project', function (): void {
		$now = new DateTimeImmutable('2026-11-07 12:00:00');
		$data = UtilityData::placeholder(new Settings(token: 'secret', teamToken: 'team-secret'), now: $now);

		expect($data->setup)->toBeNull();
		expect($data->loaded())->toBeTrue();
		expect($data->loadedAt)->toBe($now);
		expect($data->team?->name)->toBe('Team');
		expect($data->usage?->used)->toBe(0);
		expect($data->usage?->included)->toBe(0);
		expect($data->usage?->start)->toBe('2026-10-23');
		expect($data->usage?->end)->toBe('2026-11-22');
		expect($data->teamToken?->status)->toBe(CheckStatus::Ok);
		expect($data->project)->toBeNull();
		expect($data->stats)->toBeNull();
		expect($data->projectChecks)->toBe([]);
	});

	it('has the project of the settings with its statistics of the days, its domains and its checks', function (): void {
		$now = new DateTimeImmutable('2026-11-07 12:00:00');
		$data = UtilityData::placeholder(new Settings(token: 'secret', teamToken: 'team-secret', projectId: 'project-1'), $now);

		expect($data->projectId)->toBe('project-1');
		expect($data->project?->id)->toBe('project-1');
		expect($data->domains())->toHaveCount(1);
		expect($data->project?->smtpEnabled)->toBeFalse();
		expect($data->project?->redactEmailContent)->toBeFalse();
		expect($data->project?->createdAt)->toBe('2025-11-07');

		$route = $data->project?->routes[0];
		expect([$route?->slug, $route?->default, $route?->trackOpens, $route?->trackClicks])->toBe(['outgoing', true, false, false]);
		expect($data->stats?->from)->toBe('2026-10-11');
		expect($data->stats?->to)->toBe('2026-11-07');
		expect($data->stats?->totals->opened)->toBe(0);
		expect($data->stats?->totals->clicked)->toBe(0);
		expect($data->stats->daily ?? [])->toHaveCount(Stats::DAYS);
		expect(array_map(static fn(StatsDay $day): array => [$day->date, $day->sent], array_slice($data->stats->daily ?? [], -3)))
			->toBe([['2026-11-05', 0], ['2026-11-06', 0], ['2026-11-07', 0]]);
		expect(array_map(static fn(Check $check): CheckType => $check->type, $data->projectChecks))
			->toBe([CheckType::Delivery, CheckType::Sender, CheckType::Route]);
	});
});
