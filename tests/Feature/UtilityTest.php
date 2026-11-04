<?php

use craft\events\RegisterComponentTypesEvent;
use craft\services\Utilities;
use craft\web\View;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\Utility;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Sets the email settings, with the adapter as the transport.
 */
function lettermintEmail(array $settings, string $fromEmail = 'hello@acme.test'): void
{
	Craft::$app->getProjectConfig()->set('email', [
		'fromEmail' => $fromEmail,
		'transportType' => Adapter::class,
		'transportSettings' => $settings,
	]);
}

/**
 * Answers the requests of the team API with the given responses by path, and 404 for others.
 *
 * @param array<string,mixed> $responses
 */
function teamApiResponses(array $responses): Closure
{
	return static function (RequestInterface $request) use ($responses) {
		$path = substr($request->getUri()->getPath(), strlen('/v1'));

		return isset($responses[$path]) ? jsonResponse($responses[$path]) : new Response(404);
	};
}

/**
 * The content, toolbar and footer of the utility.
 */
function utilityHtml(): string
{
	return Utility::toolbarHtml() . Utility::contentHtml() . Utility::footerHtml();
}

const TEAM_API_RESPONSES = [
	'/ping' => 'pong',
	'/team' => [
		'id' => 'team-1',
		'name' => 'Acme Inc',
		'type' => 'business',
		'plan' => 'growth',
		'included_volume' => 50000,
		'verified_at' => '2026-01-01T00:00:00Z',
		'created_at' => '2026-01-01T00:00:00Z',
		'domains_count' => 2,
		'projects_count' => 2,
		'members_count' => 3,
	],
	'/team/usage' => [
		'current_period' => [
			'usage' => 12500,
			'last_incremented_at' => '2026-11-03T00:00:00Z',
			// The period contains the time the tests run.
			'period_start' => '2020-01-01T00:00:00Z',
			'period_end' => '2099-12-31T23:59:59Z',
		],
		'historical_usage' => [],
	],
	'/stats' => [
		'from' => '2026-10-06',
		'to' => '2026-11-04',
		'totals' => [
			'sent' => 1000,
			'delivered' => 980,
			'hard_bounced' => 15,
			'spam_complaints' => 2,
			'opened' => 490,
			'clicked' => null,
			'inbound' => ['received' => 0],
		],
		'daily' => [],
	],
	'/projects' => [
		'data' => [
			[
				'id' => 'project-1',
				'name' => 'Website',
				'delivery_mode' => 'sandbox',
				'routes_count' => 2,
				'domains_count' => 1,
				'last_28_days' => ['messages_transactional' => 800, 'messages_broadcast' => 150, 'messages_inbound' => 0, 'deliverability' => 98.5],
			],
			[
				'id' => 'project-2',
				'name' => 'Shop',
				'delivery_mode' => 'live',
				'routes_count' => 1,
				'domains_count' => 1,
				'last_28_days' => ['messages_transactional' => 10, 'messages_broadcast' => 0, 'messages_inbound' => 0, 'deliverability' => 100],
			],
		],
		'next_cursor' => null,
	],
	'/projects/project-1' => [
		'id' => 'project-1',
		'name' => 'Website',
		'delivery_mode' => 'sandbox',
		'token_last_used_at' => '2026-11-03T12:00:00Z',
		'token_last_used_ip' => '203.0.113.7',
		'routes' => [
			['id' => 'route-1', 'slug' => 'outgoing', 'route_type' => 'transactional', 'is_default' => true],
			['id' => 'route-2', 'slug' => 'newsletter', 'route_type' => 'broadcast', 'is_default' => false],
			['id' => 'route-3', 'slug' => 'inbox', 'route_type' => 'inbound', 'is_default' => false],
		],
		'domains' => [
			['id' => 'domain-1', 'domain' => 'acme.test'],
		],
		'last_28_days' => ['messages_transactional' => 800, 'messages_broadcast' => 150, 'messages_inbound' => 0, 'deliverability' => 98.5],
	],
	'/domains' => [
		'data' => [
			['id' => 'domain-1', 'domain' => 'acme.test', 'status' => 'verified', 'status_changed_at' => '2026-02-01T00:00:00Z'],
			['id' => 'domain-2', 'domain' => 'mail.acme.test', 'status' => 'failed_verification', 'status_changed_at' => null],
		],
		'next_cursor' => 'next',
	],
];

beforeEach(function () {
	Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
	Plugin::getInstance()->edition = Plugin::EDITION_PRO;
});

it('is named Lettermint', function () {
	expect(Utility::id())->toBe('lettermint');
	expect(Utility::displayName())->toBe('Lettermint');
	expect(Utility::icon())->toBeFile();
});

it('is only available in the pro edition', function () {
	// Craft's list of utilities queries the volumes, so the registered types are taken from the event.
	$event = new RegisterComponentTypesEvent(['types' => []]);
	Craft::$app->getUtilities()->trigger(Utilities::EVENT_REGISTER_UTILITIES, $event);

	expect($event->types)->toContain(Utility::class);
	expect(Utility::isSelectable())->toBeTrue();

	Plugin::getInstance()->edition = Plugin::EDITION_STANDARD;

	expect(Utility::isSelectable())->toBeFalse();
});

it('uses the adapter of the email settings', function () {
	expect(Utility::adapter())->toBeNull();

	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	expect(Utility::adapter())->toBeInstanceOf(Adapter::class);
	expect(Utility::adapter()->teamToken)->toBe('team-secret');
});

it('asks for Lettermint as the transport', function () {
	expect(utilityHtml())
		->toContain('Lettermint is not the transport type of the email settings.')
		->toContain('settings/email')
		->toContain('https://dash.lettermint.co/')
		->not->toContain('Refresh');
});

it('registers the styles of the utility', function () {
	utilityHtml();

	$bundle = Craft::$app->getView()->assetBundles[UtilityAsset::class] ?? null;

	expect($bundle)->not->toBeNull();
	expect($bundle->css)->toBe(['utility.css']);
	expect("{$bundle->sourcePath}/utility.css")->toBeFile();
});

it('asks for a team token', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => '$LETTERMINT_MISSING']);
	$this->http(new Response(200, [], 'pong'));

	expect(Utility::contentHtml())->toContain('Set a team API token in the email settings');
});

it('reports a rejected team token', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(new Response(401));

	expect(utilityHtml())
		->toContain('Lettermint rejects the team API token.')
		->toContain('id="lettermint-tokens"')
		->not->toContain('Usage')
		->not->toContain('Refresh');
});

it('loads the data from Lettermint once for the content, toolbar and footer', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);
	$requests = $this->http(teamApiResponses(TEAM_API_RESPONSES));

	utilityHtml();

	// The team, its usage, statistics, projects and domains, the project and the ping of the project token.
	expect($requests)->toHaveCount(7);
});

it('shows the team of the team token', function () {
	$this->env(['LETTERMINT_TEAM_TOKEN' => 'team-secret']);
	lettermintEmail(['token' => 'secret', 'teamToken' => '$LETTERMINT_TEAM_TOKEN']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	$toolbar = Utility::toolbarHtml();
	$html = Utility::contentHtml();
	$footer = Utility::footerHtml();

	expect($toolbar)
		->toContain('href="https://dash.lettermint.co/"')
		->toContain('Lettermint Dashboard');

	expect($footer)
		->toContain('Loaded from Lettermint at')
		->toContain('Refresh');

	expect($html)
		// Team
		->toContain('<h2 id="lettermint-overview" class="lettermint-hero-title">Acme Inc</h2>')
		->toContain('Growth plan')
		->toContain('Business')
		->toContain('Verified')
		->not->toContain('Not verified')
		->toContain('Team since')
		// Overview
		->toContain('12,500')
		->toContain('/ 50,000 per month')
		->toContain('25.0% of the monthly plan')
		->toContain('style="width: 25%"')
		// Statistics
		->toContain('Last 28 days')
		->toContain('<dd class="lettermint-tile-note lettermint-tone-good">98.0%</dd>')
		->toContain('<dd class="lettermint-tile-note lettermint-tone-good">1.5%</dd>')
		->toContain('<dd class="lettermint-tile-note lettermint-tone-fair">0.2%</dd>')
		->toContain('<dd class="lettermint-tile-note">50.0%</dd>')
		->not->toContain('Emails sent per day')
		->not->toContain('Clicked')
		->not->toContain('Received')
		->toContain('Oct 6, 2026 – Nov 4, 2026')
		// Projects
		->toContain('2 projects')
		->toContain('Website')
		->toContain('Sandbox')
		->toContain('950')
		->toContain('<span class="lettermint-tone-good">98.5%</span>')
		// Domains
		->toContain('2 domains')
		->toContain('acme.test')
		->toContain('<span class="lettermint-pill lettermint-pill-green">Verified</span>')
		->toContain('<span class="lettermint-pill lettermint-pill-red">Failed verification</span>')
		->toContain('Only the first 100 are listed.')
		->not->toContain('team-secret');
});

it('asks for the project of a team with several projects', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(Utility::contentHtml())
		->toContain('Set the project of the project API token in the email settings')
		->not->toContain('<span class="lettermint-badge lettermint-subtle">Current</span>');
});

it('shows the project of the settings with its checks', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	$html = Utility::contentHtml();

	expect($html)
		->toContain('<h2 id="lettermint-project">Project</h2>')
		->toContain('<h3>Website</h3>')
		->not->toContain('The only project of the team')
		->toContain('<code>project-1</code>')
		->toContain('<code>outgoing</code>')
		->toContain('203.0.113.7')
		->toContain('The project is in sandbox mode, so emails are not delivered to their recipients.')
		->toContain('The domain “acme.test” of the system email address belongs to the project.')
		->toContain('Emails use the default route “outgoing” of the project.')
		->toContain('<span class="lettermint-badge lettermint-subtle">Current</span>');
});

it('takes the project of a team with a single project', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	$responses = TEAM_API_RESPONSES;
	$responses['/projects']['data'] = [$responses['/projects']['data'][0]];
	$this->http(teamApiResponses($responses));

	expect(Utility::contentHtml())
		->toContain('<h3>Website</h3>')
		->toContain('The only project of the team');
});

it('checks the routes and the sender domain against the project', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1', 'route' => 'inbox'], 'hello@other.test');
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(Utility::contentHtml())
		->toContain('The route “inbox” is not an outgoing route of the project.')
		->toContain('The domain “other.test” of the system email address is not a domain of the project.');
});

it('checks a configured route that belongs to the project', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1', 'route' => 'newsletter']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(Utility::contentHtml())->toContain('The route “newsletter” belongs to the project.');
});

it('warns about a sender domain that is not verified', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);

	$responses = TEAM_API_RESPONSES;
	$responses['/domains']['data'][0]['status'] = 'pending_verification';
	$this->http(teamApiResponses($responses));

	expect(Utility::contentHtml())->toContain('The domain “acme.test” of the system email address is not fully verified.');
});

it('reports a project that is not in the team', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'unknown']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(Utility::contentHtml())->toContain('The project “unknown” is not a project of the team.');
});

it('shows the sections that could be loaded', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(static fn (RequestInterface $request) => match ($request->getUri()->getPath()) {
		'/v1/team' => jsonResponse(TEAM_API_RESPONSES['/team']),
		'/v1/team/usage' => new Response(403),
		default => new Response(500),
	});

	expect(utilityHtml())
		->toContain('Acme Inc')
		->toContain('This could not be loaded from Lettermint.');
});

it('reports a section the team token is not allowed to read', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	$this->http(static fn (RequestInterface $request) => $request->getUri()->getPath() === '/v1/domains'
		? new Response(403)
		: teamApiResponses(TEAM_API_RESPONSES)($request));

	expect(Utility::contentHtml())->toContain('The team API token is not allowed to read this.');
});

it('marks a team that is not verified', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	$responses = TEAM_API_RESPONSES;
	$responses['/team']['verified_at'] = null;
	$this->http(teamApiResponses($responses));

	expect(Utility::contentHtml())->toContain('<span class="lettermint-badge lettermint-warning">Not verified</span>');
});

it('charts the emails sent per day', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	$responses = TEAM_API_RESPONSES;
	$responses['/stats']['daily'] = [
		['date' => '2026-11-02', 'sent' => 40],
		['date' => '2026-11-03', 'sent' => 0],
		['date' => '2026-11-04', 'sent' => 10],
	];
	$this->http(teamApiResponses($responses));

	expect(Utility::contentHtml())
		->toContain('Emails sent per day')
		->toContain('Peak of 40')
		->toContain('style="--lettermint-bar: 100%"')
		->toContain('class="lettermint-chart-bar lettermint-chart-bar-empty"')
		->toContain('style="--lettermint-bar: 25%"');
});

it('colours poor rates', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	$responses = TEAM_API_RESPONSES;
	$responses['/stats']['totals'] = ['sent' => 100, 'delivered' => 80, 'hard_bounced' => 6, 'spam_complaints' => 1];
	$responses['/projects']['data'][0]['last_28_days']['deliverability'] = 0.85;
	$this->http(teamApiResponses($responses));

	expect(Utility::contentHtml())
		->toContain('<dd class="lettermint-tile-note lettermint-tone-poor">80.0%</dd>')
		->toContain('<dd class="lettermint-tile-note lettermint-tone-poor">6.0%</dd>')
		->toContain('<dd class="lettermint-tile-note lettermint-tone-poor">1.0%</dd>')
		->toContain('<span class="lettermint-tone-poor">85.0%</span>');
});

it('takes the statistics of the project from the list of projects', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);

	// The project details leave out the statistics without the include, or report them as zero.
	$responses = TEAM_API_RESPONSES;
	$responses['/projects/project-1']['last_28_days'] = ['messages_transactional' => 0, 'messages_broadcast' => 0, 'messages_inbound' => 0, 'deliverability' => 0];
	$this->http(teamApiResponses($responses));

	$html = Utility::contentHtml();

	expect($html)
		->toContain('<dd>950</dd>')
		->toContain('<dd><span class="lettermint-tone-good">98.5%</span></dd>')
		->not->toContain('>0.0%<');
});

it('shows no deliverability without emails', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);

	$responses = TEAM_API_RESPONSES;
	$responses['/projects']['data'][0]['last_28_days'] = ['messages_transactional' => 0, 'messages_broadcast' => 0, 'messages_inbound' => 0, 'deliverability' => 0];
	$this->http(teamApiResponses($responses));

	expect(Utility::contentHtml())
		->toContain('<dd>–</dd>')
		->not->toContain('>0.0%<');
});

it('shows the status of the tokens', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	$html = Utility::contentHtml();

	expect($html)
		->toContain('<h2 id="lettermint-tokens">API Tokens</h2>')
		->toContain('<p class="lettermint-token-name">Project API Token</p>')
		->toContain('The project API token, which sends the emails.')
		->toContain('The team API token, which this page is loaded with.')
		->not->toContain('<span class="lettermint-pill lettermint-pill-red">Invalid</span>');
	expect(substr_count($html, 'lettermint-pill lettermint-pill-green">Valid'))->toBe(2);
});

it('pings Lettermint with the project token', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$responses = teamApiResponses(TEAM_API_RESPONSES);
	$this->http(static function (RequestInterface $request) use ($responses) {
		if ($request->getUri()->getPath() === '/v1/ping') {
			expect($request->getHeaderLine('x-lettermint-token'))->toBe('secret');

			return new Response(401);
		}

		return $responses($request);
	});

	expect(Utility::contentHtml())
		->toContain('<span class="lettermint-pill lettermint-pill-red">Invalid</span>')
		->toContain('Lettermint rejects the token, or does not allow it from the IP address of this server.')
		->toContain('<h2 id="lettermint-overview" class="lettermint-hero-title">Acme Inc</h2>');
});

it('shows the status of the project token without a team token', function () {
	lettermintEmail(['token' => 'secret']);
	$this->http(new Response(200, [], 'pong'));

	expect(Utility::contentHtml())
		->toContain('Set a team API token in the email settings')
		->toContain('<span class="lettermint-pill lettermint-pill-green">Valid</span>')
		->toContain('<span class="lettermint-pill lettermint-pill-gray">Not set</span>');
});

it('reports a team token that could not be checked', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(static fn (RequestInterface $request) => $request->getUri()->getPath() === '/v1/ping'
		? new Response(200, [], 'pong')
		: new Response(503));

	expect(Utility::contentHtml())
		->toContain('<span class="lettermint-pill lettermint-pill-gray">Not checked</span>')
		->toContain('Lettermint could not be reached to check the token.');
});

it('takes a team token that is not allowed to read the team as valid', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(static fn (RequestInterface $request) => $request->getUri()->getPath() === '/v1/ping'
		? new Response(200, [], 'pong')
		: new Response(403));

	expect(substr_count(Utility::contentHtml(), 'lettermint-pill lettermint-pill-green">Valid'))->toBe(2);
});

it('shows the dates of a current period', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(Utility::contentHtml())->toContain('1/1/2020 – 12/31/2099');
});

it('shows the usage of a period that has passed without its dates', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	// Lettermint reports the usage of its dashboard, with the dates of a period that has passed.
	$responses = TEAM_API_RESPONSES;
	$responses['/team/usage'] = [
		'current_period' => ['usage' => 80, 'period_start' => '2025-12-11T00:00:00Z', 'period_end' => '2026-01-11T00:00:00Z'],
		'historical_usage' => [],
	];
	$this->http(teamApiResponses($responses));

	expect(Utility::contentHtml())
		->toContain('80')
		->toContain('/ 50,000 per month')
		->toContain('0.2% of the monthly plan')
		->toContain('lettermint-meter')
		->not->toContain('12/11/2025')
		->not->toContain('1/11/2026');
});

it('shows a full usage as exceeded', function () {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	$responses = TEAM_API_RESPONSES;
	$responses['/team/usage']['current_period']['usage'] = 60000;
	$this->http(teamApiResponses($responses));

	expect(Utility::contentHtml())
		->toContain('lettermint-meter error')
		->toContain('style="width: 100%"');
});
