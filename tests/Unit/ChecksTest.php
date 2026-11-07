<?php

declare(strict_types=1);

use craft\models\Site;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Check;
use SomehowDigital\Craft\Lettermint\CheckResult;
use SomehowDigital\Craft\Lettermint\Checks;
use SomehowDigital\Craft\Lettermint\CheckStatus;
use SomehowDigital\Craft\Lettermint\CheckType;
use SomehowDigital\Craft\Lettermint\data\Domain;
use SomehowDigital\Craft\Lettermint\data\Period;
use SomehowDigital\Craft\Lettermint\data\Project;
use SomehowDigital\Craft\Lettermint\data\Route;
use SomehowDigital\Craft\Lettermint\data\Section;
use SomehowDigital\Craft\Lettermint\data\SectionError;
use SomehowDigital\Craft\Lettermint\data\Sections;
use SomehowDigital\Craft\Lettermint\data\Team;
use SomehowDigital\Craft\Lettermint\data\Usage;
use SomehowDigital\Craft\Lettermint\Edition;
use SomehowDigital\Craft\Lettermint\Settings;

/**
 * The checks of the settings against the project, by ID.
 *
 * @param array<string,mixed> $settings
 * @param list<Site> $sites
 * @return array<string,Check>
 */
function projectChecks(array $settings, Project $project, ?string $email = 'hello@acme.test', array $sites = []): array
{
	Craft::$app->getProjectConfig()->set('email', ['fromEmail' => $email ?? '', 'transportType' => Adapter::class]);

	$checks = new Checks((new Adapter(['token' => 'secret', ...$settings]))->resolve(), null, new Sections(
		team: Section::failed(SectionError::Forbidden),
		usage: Section::failed(SectionError::Forbidden),
		project: Section::loaded($project),
		// The listed domains give the domains of the project their status.
		domains: Section::loaded($project->domains),
	), $sites);

	return array_column($checks->result()->projectChecks, null, 'id');
}

/**
 * A site, which is not added to Craft, as the checks are given their sites.
 */
function site(string $uid, string $handle, string $name): Site
{
	return new Site(['uid' => $uid, 'handle' => $handle, 'name' => $name, 'language' => 'en-US']);
}

beforeEach(function (): void {
	plugin()->edition = Edition::Pro->value;
});

describe('sender', function (): void {
	it('skips the sender without a domain in the system email address', function (): void {
		expect(projectChecks([], new Project(domains: [new Domain('domain-1', 'nobody')]), 'nobody'))->not->toHaveKey('sender');
	});

	it('skips the sender without a system email address', function (): void {
		expect(projectChecks([], new Project(domains: [new Domain('domain-1', 'acme.test')]), null))->not->toHaveKey('sender');
	});

	it('reports the sender domain when the project has no domains', function (): void {
		expect(projectChecks([], new Project())['sender']->status)->toBe(CheckStatus::Error);
	});

	it('matches the domains regardless of their case', function (string $email, string $domain): void {
		expect(projectChecks([], new Project(domains: [new Domain(), new Domain('domain-1', $domain)]), $email)['sender']->status)->toBe(CheckStatus::Ok);
	})->with([
		'system email address' => ['hello@ACME.test', 'acme.test'],
		'domain of the project' => ['hello@acme.test', 'ACME.test'],
	]);

	it('warns about a sender domain that is not verified', function (?string $status, CheckStatus $expected): void {
		expect(projectChecks([], new Project(domains: [new Domain('domain-1', 'acme.test', $status)]))['sender']->status)->toBe($expected);
	})->with([
		'verified' => ['verified', CheckStatus::Ok],
		'pending' => ['pending_verification', CheckStatus::Warning],
		// A domain that was not listed has no status.
		'unknown' => [null, CheckStatus::Ok],
	]);
});

describe('routes', function (): void {
	it('takes the last default route of the project', function (): void {
		$checks = projectChecks([], new Project(routes: [
			new Route('first', default: true),
			new Route('last', default: true),
			new Route('other'),
		]));

		expect($checks['route']->text())->toBe('Emails use the default route “last” of the project.');
	});

	it('checks no route without one of the adapter or a default route of the project', function (): void {
		expect(projectChecks([], new Project(routes: [new Route('outgoing')])))->not->toHaveKey('route');
	});

	it('names a default route without a slug', function (): void {
		expect(projectChecks([], new Project(routes: [new Route(default: true)]))['route']->text())
			->toBe('Emails use the default route “” of the project.');
	});

	it('checks the routes of the sites that have one', function (): void {
		$checks = projectChecks(
			['sites' => ['site-a' => ['route' => 'outgoing'], 'site-b' => ['route' => 'unknown']]],
			new Project(routes: [new Route('outgoing', 'transactional'), new Route('unknown', 'inbound')]),
			sites: [site('site-c', 'blog', 'Blog'), site('site-a', 'shop', 'Shop'), site('site-b', 'archive', 'Archive')],
		);

		expect($checks)->not->toHaveKey('route.blog');
		expect($checks['route.shop'])->toEqual(new Check(CheckType::Route, CheckStatus::Ok, 'The route “{route}” of {site} belongs to the project.', ['route' => 'outgoing', 'site' => 'Shop'], 'shop'));
		expect($checks['route.archive'])->toEqual(new Check(CheckType::Route, CheckStatus::Error, 'The route “{route}” of {site} is not an outgoing route of the project.', ['route' => 'unknown', 'site' => 'Archive'], 'archive'));
	});
});

describe('result', function (): void {
	it('checks no usage that is not known', function (): void {
		$checks = new Checks(new Settings(token: 'secret'), null, new Sections(
			team: Section::loaded(new Team()),
			usage: Section::failed(SectionError::Forbidden),
		), []);

		expect(array_column($checks->result()->all(), 'id'))->toBe(['token', 'teamToken']);
	});

	it('checks nothing after a team token that Lettermint rejects', function (): void {
		$result = (new Checks(new Settings(token: 'secret'), null, new Sections(
			team: Section::failed(SectionError::Invalid),
			usage: Section::loaded(new Usage(new Period(250))),
			project: Section::loaded(new Project()),
		), []))->result();

		expect($result->teamToken?->status)->toBe(CheckStatus::Warning);
		expect($result->usage)->toBeNull();
		expect($result->project)->toBeNull();
		expect($result->projectChecks)->toBe([]);
		expect(array_column($result->all(), 'id'))->toBe(['token', 'teamToken']);
	});

	it('checks only the project token without a team token', function (): void {
		expect((new Checks(new Settings(token: 'secret'), null, null, []))->result())
			->toEqual(new CheckResult(Check::error(CheckType::Token, 'The project API token is not set.')));
	});
});
