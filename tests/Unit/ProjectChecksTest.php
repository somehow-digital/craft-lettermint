<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Check;
use SomehowDigital\Craft\Lettermint\CheckStatus;
use SomehowDigital\Craft\Lettermint\data\Domain;
use SomehowDigital\Craft\Lettermint\data\Project;
use SomehowDigital\Craft\Lettermint\data\Route;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\ProjectChecks;

/**
 * The checks of the settings against the project, by ID.
 *
 * @param array<string,mixed> $settings
 * @return array<string,Check>
 */
function projectChecks(array $settings, Project $project): array
{
	return array_column((new ProjectChecks(new Adapter(['token' => 'secret', ...$settings]), $project))->all(), null, 'id');
}

/**
 * Sets the system email address.
 */
function systemEmail(string $email): void
{
	Craft::$app->getProjectConfig()->set('email', ['fromEmail' => $email, 'transportType' => Adapter::class]);
}

beforeEach(function (): void {
	plugin()->edition = Plugin::EDITION_PRO;
});

describe('sender', function (): void {
	it('skips the sender without a domain in the system email address', function (): void {
		systemEmail('nobody');

		expect(projectChecks([], new Project(domains: [new Domain('domain-1', 'nobody')])))->not->toHaveKey('sender');
	});

	it('reports the sender domain when the project has no domains', function (): void {
		systemEmail('hello@acme.test');

		expect(projectChecks([], new Project())['sender']->status)->toBe(CheckStatus::Error);
	});

	it('matches the domains regardless of their case', function (string $email, string $domain): void {
		systemEmail($email);

		expect(projectChecks([], new Project(domains: [new Domain(), new Domain('domain-1', $domain)]))['sender']->status)->toBe(CheckStatus::Ok);
	})->with([
		'system email address' => ['hello@ACME.test', 'acme.test'],
		'domain of the project' => ['hello@acme.test', 'ACME.test'],
	]);

	it('warns about a sender domain that is not verified', function (?string $status, CheckStatus $expected): void {
		systemEmail('hello@acme.test');

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

		expect($checks['route']->text)->toBe('Emails use the default route “last” of the project.');
	});

	it('checks no route without one of the adapter or a default route of the project', function (): void {
		expect(projectChecks([], new Project(routes: [new Route('outgoing')])))->not->toHaveKey('route');
	});

	it('names a default route without a slug', function (): void {
		expect(projectChecks([], new Project(routes: [new Route(default: true)]))['route']->text)
			->toBe('Emails use the default route “” of the project.');
	});

	it('checks the routes of the sites that have one, also the disabled ones', function (): void {
		addSite('site-c', 'blog', 'Blog');
		addSite('site-a', 'shop', 'Shop');
		addSite('site-b', 'archive', 'Archive', enabled: false);

		$checks = projectChecks(
			['sites' => ['site-a' => ['route' => 'outgoing'], 'site-b' => ['route' => 'unknown']]],
			new Project(routes: [new Route('outgoing', 'transactional'), new Route('unknown', 'inbound')]),
		);

		expect($checks)->not->toHaveKey('route.blog');
		expect($checks['route.shop'])->toEqual(new Check('route.shop', CheckStatus::Ok, 'The route “outgoing” of Shop belongs to the project.'));
		expect($checks['route.archive'])->toEqual(new Check('route.archive', CheckStatus::Error, 'The route “unknown” of Archive is not an outgoing route of the project.'));
	});
});
