<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\ProjectChecks;

/**
 * The checks of the settings against the project, by ID.
 *
 * @param array<string,mixed> $settings
 * @param array<mixed> $project
 * @param array<mixed> $domains
 * @return array<string,array{id:string,status:string,text:string}>
 */
function projectChecks(array $settings, array $project, array $domains = []): array
{
	return array_column((new ProjectChecks(new Adapter(['token' => 'secret', ...$settings]), $project, $domains))->all(), null, 'id');
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

		expect(projectChecks([], ['domains' => [['id' => 'domain-1', 'domain' => 'nobody']]]))->not->toHaveKey('sender');
	});

	it('reports the sender domain when the project has no domains', function (): void {
		systemEmail('hello@acme.test');

		expect(projectChecks([], [])['sender']['status'])->toBe('error');
	});

	it('matches the domains regardless of their case', function (string $email, string $domain): void {
		systemEmail($email);

		expect(projectChecks([], ['domains' => [['id' => 'domain-1', 'domain' => $domain]]])['sender']['status'])->toBe('ok');
	})->with([
		'system email address' => ['hello@ACME.test', 'acme.test'],
		'domain of the project' => ['hello@acme.test', 'ACME.test'],
	]);
});

describe('routes', function (): void {
	it('takes the last default route of the project', function (): void {
		$checks = projectChecks([], ['routes' => [
			['slug' => 'first', 'is_default' => true],
			['slug' => 'last', 'is_default' => true],
			['slug' => 'other'],
		]]);

		expect($checks['route']['text'])->toBe('Emails use the default route “last” of the project.');
	});

	it('names a default route without a slug', function (): void {
		expect(projectChecks([], ['routes' => [['is_default' => true]]])['route']['text'])
			->toBe('Emails use the default route “” of the project.');
	});

	it('only takes slugs that are strings', function (): void {
		expect(projectChecks(['route' => '5'], ['routes' => [['slug' => 5]]])['route']['status'])->toBe('error');
	});

	it('checks the routes of the sites that have one, also the disabled ones', function (): void {
		addSite('site-c', 'blog', 'Blog');
		addSite('site-a', 'shop', 'Shop');
		addSite('site-b', 'archive', 'Archive', enabled: false);

		$checks = projectChecks(
			['sites' => ['site-a' => ['route' => 'outgoing'], 'site-b' => ['route' => 'unknown']]],
			['routes' => [['slug' => 'outgoing', 'route_type' => 'transactional'], ['slug' => 'unknown', 'route_type' => 'inbound']]],
		);

		expect($checks)->not->toHaveKey('route.blog');
		expect($checks['route.shop'])->toBe(['id' => 'route.shop', 'status' => 'ok', 'text' => 'The route “outgoing” of Shop belongs to the project.']);
		expect($checks['route.archive'])->toBe(['id' => 'route.archive', 'status' => 'error', 'text' => 'The route “unknown” of Archive is not an outgoing route of the project.']);
	});
});
