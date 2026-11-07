<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\enums\CheckType;
use SomehowDigital\Craft\Lettermint\enums\SectionError;
use SomehowDigital\Craft\Lettermint\enums\Setup;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\helpers\Checks;
use SomehowDigital\Craft\Lettermint\models\Check;
use SomehowDigital\Craft\Lettermint\models\Domain;
use SomehowDigital\Craft\Lettermint\models\Period;
use SomehowDigital\Craft\Lettermint\models\Project;
use SomehowDigital\Craft\Lettermint\models\Section;
use SomehowDigital\Craft\Lettermint\models\Sections;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\Team;
use SomehowDigital\Craft\Lettermint\models\Usage;
use SomehowDigital\Craft\Lettermint\models\UsageSummary;
use SomehowDigital\Craft\Lettermint\models\UtilityData;

/**
 * The data of settings and sections, with their checks.
 */
function utilityData(?Settings $settings, ?Sections $sections): UtilityData
{
	$checks = $settings instanceof Settings ? Checks::build($settings, TokenStatus::Valid, $sections, []) : null;

	return UtilityData::from($settings, $checks, new DateTimeImmutable('2026-11-06T12:00:00Z'));
}

/**
 * Sections with the project and its listed domains, and a team and usage that do not matter.
 *
 * @param Section<Project>|null $project
 * @param Section<list<Domain>>|null $domains
 */
function projectData(?Section $project, ?Section $domains): UtilityData
{
	return utilityData(new Settings(token: 'secret', teamToken: 'team-secret', projectId: 'project-1'), new Sections(
		Section::loaded(new Team()),
		Section::loaded(new Usage()),
		$project,
		$domains,
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
		$data = utilityData(new Settings(token: 'secret', teamToken: 'team-secret'), new Sections(
			Section::failed(SectionError::Invalid),
			Section::failed(SectionError::Invalid),
		));

		expect($data->setup)->toBe(Setup::Invalid);
		expect($data->teamToken)->toEqual(Check::warning(CheckType::TeamToken, 'Lettermint rejects the team API token.'));
		expect($data->usageError)->toBe(SectionError::Invalid);
		expect($data->loaded())->toBeTrue();
	});

	it('takes the team, its usage, the project and its statistics from the sections', function (): void {
		$team = Section::loaded(new Team(includedVolume: 1000));
		$stats = Section::failed(SectionError::Unavailable);
		$loadedAt = new DateTimeImmutable('2026-11-06T12:00:00Z');
		$settings = new Settings(token: 'secret', teamToken: 'team-secret', projectId: 'project-1');
		$sections = new Sections(
			$team,
			Section::loaded(new Usage(new Period(250))),
			Section::loaded(new Project('project-1', deliveryMode: Project::DELIVERY_MODE_SANDBOX, domains: [new Domain('domain-1', 'acme.test')])),
			Section::loaded([new Domain('domain-1', 'acme.test', 'verified')]),
			$stats,
		);

		$data = UtilityData::from($settings, Checks::build($settings, TokenStatus::Valid, $sections, []), $loadedAt);

		expect($data->setup)->toBeNull();
		expect($data->team)->toBe($team);
		expect($data->usage)->toEqual(new UsageSummary(250, 1000));
		expect($data->usageError)->toBeNull();
		expect($data->projectId)->toBe('project-1');
		expect($data->project?->domains)->toEqual([new Domain('domain-1', 'acme.test', 'verified')]);
		expect($data->projectError)->toBeNull();
		expect($data->projectChecks)->toEqual([Check::warning(CheckType::Delivery, 'The project is in sandbox mode, so emails are not delivered to their recipients.')]);
		expect($data->stats)->toBe($stats);
		expect($data->loadedAt)->toBe($loadedAt);
	});

	it('keeps the error of a project that could not be loaded', function (): void {
		$data = projectData(Section::failed(SectionError::Missing), null);

		expect($data->project)->toBeNull();
		expect($data->projectError)->toBe(SectionError::Missing);
	});

	it('loads the data at the time it is built', function (): void {
		$before = new DateTimeImmutable();

		expect(UtilityData::from(null, null)->loadedAt)->toBeGreaterThanOrEqual($before);
	});
});

describe('domains', function (): void {
	it('lists the domains of the project by name, with the status of the listed domains', function (): void {
		$data = projectData(
			Section::loaded(new Project(id: 'project-1', domains: [
				new Domain('domain-2', 'b.acme.test'),
				new Domain('domain-1', 'a.acme.test', statusChangedAt: '2026-01-01'),
				new Domain(null, 'c.acme.test'),
				new Domain('domain-3'),
				new Domain('domain-4', '1.acme.test'),
			])),
			Section::loaded([
				new Domain('domain-1', 'a.acme.test', 'verified', '2026-02-01'),
				new Domain('domain-2', 'b.acme.test', 'pending'),
				// Listed domains without an ID match none of the project.
				new Domain(null, 'c.acme.test', 'failed_verification'),
			]),
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

	it('has no domains without a project', function (?Section $section): void {
		/** @var Section<Project>|null $section */
		expect(projectData($section, null)->domains())->toBe([]);
	})->with([
		'not requested' => [null],
		'not loaded' => [Section::failed(SectionError::Missing)],
	]);

	it('keeps the domains of the project without the listed domains', function (): void {
		$data = projectData(
			Section::loaded(new Project(id: 'project-1', domains: [new Domain('domain-1', 'acme.test')])),
			Section::failed(SectionError::Unavailable),
		);

		expect($data->domains())->toEqual([new Domain('domain-1', 'acme.test')]);
	});
});
