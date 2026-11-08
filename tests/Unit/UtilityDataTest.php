<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\enums\ApiError;
use SomehowDigital\Craft\Lettermint\enums\CheckType;
use SomehowDigital\Craft\Lettermint\enums\Setup;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\models\ApiResult;
use SomehowDigital\Craft\Lettermint\models\Check;
use SomehowDigital\Craft\Lettermint\models\Checks;
use SomehowDigital\Craft\Lettermint\models\Domain;
use SomehowDigital\Craft\Lettermint\models\Period;
use SomehowDigital\Craft\Lettermint\models\Project;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\Stats;
use SomehowDigital\Craft\Lettermint\models\Team;
use SomehowDigital\Craft\Lettermint\models\TeamApiData;
use SomehowDigital\Craft\Lettermint\models\UsageSummary;
use SomehowDigital\Craft\Lettermint\models\UtilityData;

function utilityData(?Settings $settings, ?TeamApiData $api): UtilityData
{
	$checks = $settings instanceof Settings ? Checks::build($settings, TokenStatus::Valid, $api, []) : null;

	return UtilityData::from($settings, $api, $checks, new DateTimeImmutable('2026-11-06T12:00:00Z'));
}

/**
 * Data with the project, and an irrelevant team and usage.
 *
 * @param ApiResult<Project>|null $project
 */
function projectData(?ApiResult $project): UtilityData
{
	return utilityData(new Settings(token: 'secret', teamToken: 'team-secret', projectId: 'project-1'), new TeamApiData(
		ApiResult::loaded(new Team()),
		ApiResult::loaded(null),
		$project,
	));
}

describe('from', function (): void {
	it('needs Lettermint as the transport', function (): void {
		$data = utilityData(null, null);

		expect($data->setup)->toBe(Setup::Transport);
		expect($data->token)->toBeNull();
		expect($data->teamToken)->toBeNull();
		expect($data->projectChecks)->toBe([]);
		expect($data->loaded())->toBeFalse();
	});

	it('needs a team token', function (): void {
		$data = utilityData(new Settings(token: 'secret'), null);

		expect($data->setup)->toBe(Setup::Token);
		expect($data->token)->toEqual(Check::ok(CheckType::Token, 'Lettermint accepts the project API token.'));
		expect($data->teamToken)->toBeNull();
		expect($data->team)->toBeNull();
		expect($data->usage)->toBeNull();
		expect($data->usageError)->toBeNull();
		expect($data->loaded())->toBeFalse();
	});

	it('needs a team token that Lettermint accepts', function (): void {
		$data = utilityData(new Settings(token: 'secret', teamToken: 'team-secret'), new TeamApiData(
			ApiResult::failed(ApiError::Invalid),
			ApiResult::failed(ApiError::Invalid),
		));

		expect($data->setup)->toBe(Setup::Invalid);
		expect($data->teamToken)->toEqual(Check::warning(CheckType::TeamToken, 'Lettermint rejects the team API token.'));
		expect($data->team)->toBeNull();
		expect($data->teamError)->toBe(ApiError::Invalid);
		expect($data->usageError)->toBe(ApiError::Invalid);
		expect($data->loaded())->toBeTrue();
	});

	it('takes the team, its usage, the project and its statistics from the results', function (): void {
		$team = new Team(includedVolume: 1000);
		$loadedAt = new DateTimeImmutable('2026-11-06T12:00:00Z');
		$settings = new Settings(token: 'secret', teamToken: 'team-secret', projectId: 'project-1');
		$api = new TeamApiData(
			ApiResult::loaded($team),
			ApiResult::loaded(new Period(250)),
			ApiResult::loaded(new Project('project-1', deliveryMode: Project::DELIVERY_MODE_SANDBOX, domains: [new Domain('domain-1', 'acme.test', 'verified')])),
			ApiResult::failed(ApiError::Unavailable),
		);

		$data = UtilityData::from($settings, $api, Checks::build($settings, TokenStatus::Valid, $api, []), $loadedAt);

		expect($data->setup)->toBeNull();
		expect($data->team)->toBe($team);
		expect($data->teamError)->toBeNull();
		expect($data->usage)->toEqual(new UsageSummary(250, 1000));
		expect($data->usageError)->toBeNull();
		expect($data->projectId)->toBe('project-1');
		expect($data->project?->domains)->toEqual([new Domain('domain-1', 'acme.test', 'verified')]);
		expect($data->projectError)->toBeNull();
		expect($data->projectChecks)->toEqual([Check::warning(CheckType::Delivery, 'The project is in sandbox mode, so emails are not delivered to their recipients.')]);
		expect($data->stats)->toBeNull();
		expect($data->statsError)->toBe(ApiError::Unavailable);
		expect($data->loadedAt)->toBe($loadedAt);
	});

	it('takes the statistics that were loaded', function (): void {
		$stats = new Stats('2026-10-06', '2026-11-04');
		$data = utilityData(new Settings(token: 'secret', teamToken: 'team-secret', projectId: 'project-1'), new TeamApiData(
			ApiResult::loaded(new Team()),
			ApiResult::loaded(null),
			ApiResult::loaded(new Project('project-1')),
			ApiResult::loaded($stats),
		));

		expect($data->stats)->toBe($stats);
		expect($data->statsError)->toBeNull();
	});

	it('keeps the error of a project that could not be loaded', function (): void {
		$data = projectData(ApiResult::failed(ApiError::Missing));

		expect($data->project)->toBeNull();
		expect($data->projectError)->toBe(ApiError::Missing);
	});

	it('loads the data at the time it is built', function (): void {
		$before = new DateTimeImmutable();

		expect(UtilityData::from(null, null, null)->loadedAt)->toBeGreaterThanOrEqual($before);
	});
});

describe('domains', function (): void {
	it('lists the domains of the project by name, with the status of the listed domains', function (): void {
		$data = projectData(
			ApiResult::loaded((new Project(id: 'project-1', domains: [
				new Domain('domain-2', 'b.acme.test'),
				new Domain('domain-1', 'a.acme.test', statusChangedAt: '2026-01-01'),
				new Domain(null, 'c.acme.test'),
				new Domain('domain-3'),
				new Domain('domain-4', '1.acme.test'),
			]))->withStatuses([
				new Domain('domain-1', 'a.acme.test', 'verified', '2026-02-01'),
				new Domain('domain-2', 'b.acme.test', 'pending'),
				// Listed domains without an ID match nothing.
				new Domain(null, 'c.acme.test', 'failed_verification'),
			])),
		);

		// Domains without a name come first.
		expect($data->domains())->toEqual([
			new Domain('domain-3'),
			new Domain('domain-4', '1.acme.test'),
			new Domain('domain-1', 'a.acme.test', 'verified', '2026-01-01'),
			new Domain('domain-2', 'b.acme.test', 'pending'),
			new Domain(null, 'c.acme.test'),
		]);
		expect($data->project?->domains)->toHaveCount(5);
	});

	it('has no domains without a project', function (?ApiResult $result): void {
		/** @var ApiResult<Project>|null $result */
		expect(projectData($result)->domains())->toBe([]);
	})->with([
		'not requested' => [null],
		'not loaded' => [ApiResult::failed(ApiError::Missing)],
	]);
});
