<?php

declare(strict_types=1);

use craft\events\RegisterComponentTypesEvent;
use craft\services\Utilities;
use craft\web\View;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\Utility;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** The path of the ping of Lettermint. */
const PING_PATH = '/v1/ping';

beforeEach(function (): void {
	Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
	plugin()->edition = Plugin::EDITION_PRO;
});

it('is named Lettermint', function (): void {
	expect(Utility::id())->toBe('lettermint');
	expect(Utility::displayName())->toBe('Lettermint');
	// A directory exists as well, which the icon is not.
	expect(is_file(Utility::icon()))->toBeTrue();
	expect(Utility::icon())->toEndWith('.svg');
});

it('is only available in the pro edition', function (): void {
	// Craft's list of utilities queries the volumes, so the registered types are taken from the event.
	$event = new RegisterComponentTypesEvent(['types' => []]);
	Craft::$app->getUtilities()->trigger(Utilities::EVENT_REGISTER_UTILITIES, $event);

	expect($event->types)->toContain(Utility::class);
	expect(Utility::isSelectable())->toBeTrue();

	plugin()->edition = Plugin::EDITION_STANDARD;

	expect(Utility::isSelectable())->toBeFalse();
});

it('uses the adapter of the email settings', function (): void {
	expect(Utility::adapter())->toBeNull();

	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	expect(Utility::adapter())->toBeInstanceOf(Adapter::class);
	expect(Utility::adapter()?->teamToken)->toBe('team-secret');
});

it('asks for Lettermint as the transport', function (): void {
	expect(utilityHtml())
		->toContain('Lettermint is not the transport type of the email settings.')
		->toContain('settings/email')
		->toContain('https://dash.lettermint.co/')
		->not->toContain('Refresh');
});

it('registers the styles of the utility', function (): void {
	utilityHtml();

	$bundle = assertInstance(Craft::$app->getView()->assetBundles[UtilityAsset::class] ?? null, UtilityAsset::class);

	expect($bundle->css)->toBe(['utility.css']);
	expect(assertString($bundle->sourcePath) . '/utility.css')->toBeFile();
});

it('asks for a team token', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => '$LETTERMINT_MISSING']);
	$this->http(new Response(200, [], 'pong'));

	expect(Utility::contentHtml())->toContain('Set a team API token in the email settings');
});

it('reports a rejected team token', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(new Response(401));

	expect(utilityHtml())
		->toContain('Lettermint rejects the team API token.')
		->toContain('id="lettermint-tokens"')
		->not->toContain('Usage')
		// The token can be accepted again without other settings, which only a refresh loads.
		->toContain('Refresh');
});

it('loads the data from Lettermint once for the content, toolbar and footer', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);
	$requests = $this->http(teamApiResponses(TEAM_API_RESPONSES));

	utilityHtml();

	// The team, its usage, the project, its domains and statistics, and the ping of the project token.
	expect($requests)->toHaveCount(6);
});

it('shows the team of the team token', function (): void {
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
		->toContain('max="50000" value="12500"')
		// The counts of the projects, domains and members of the team.
		->not->toContain('<dt>Projects</dt>')
		->not->toContain('<dt>Domains</dt>')
		->not->toContain('<dt>Members</dt>')
		->not->toContain('team-secret');
});

it('requests nothing but the team without a project', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$requests = $this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(Utility::contentHtml())
		->toContain('Acme Inc')
		->toContain('Set the project of the project API token in the email settings')
		->not->toContain('Website')
		->not->toContain('acme.test')
		->not->toContain('Last 28 days');

	expect(array_map(static fn(RequestInterface $request): string => $request->getUri()->getPath(), $requests->getArrayCopy()))
		->toBe(['/v1/team', '/v1/team/usage', PING_PATH]);
});

it('shows the statistics and domains of the project only', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);
	$requests = $this->http(teamApiResponses(TEAM_API_RESPONSES));

	$html = Utility::contentHtml();

	expect($html)
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
		// Domains of the project, with the status of the domains of the team.
		->toContain('1 domain')
		->toContain('<th scope="row">acme.test</th>')
		->toContain('<span class="lettermint-pill lettermint-pill-green">Verified</span>')
		// Listed domains that are not of the project.
		->not->toContain('mail.acme.test')
		->not->toContain('Failed verification');

	$queries = [];

	foreach ($requests as $request) {
		$queries[$request->getUri()->getPath()] = urldecode($request->getUri()->getQuery());
	}

	expect($queries['/v1/domains'])->toContain('filter[project]=project-1');
	expect($queries['/v1/stats'])->toContain('project_id=project-1');
});

it('shows the project of the settings with its checks', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	$html = Utility::contentHtml();

	expect($html)
		->toContain('<h2 id="lettermint-project">Project</h2>')
		->toContain('<h3>Website</h3>')
		->toContain('<code>project-1</code>')
		->toContain('<code>outgoing</code>')
		->toContain('203.0.113.7')
		->toContain('The project is in sandbox mode, so emails are not delivered to their recipients.')
		->toContain('The domain “acme.test” of the system email address belongs to the project.')
		->toContain('Emails use the default route “outgoing” of the project.');
});

it('checks the routes and the sender domain against the project', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1', 'route' => 'inbox'], 'hello@other.test');
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(Utility::contentHtml())
		->toContain('The route “inbox” is not an outgoing route of the project.')
		->toContain('The domain “other.test” of the system email address is not a domain of the project.');
});

it('checks a configured route that belongs to the project', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1', 'route' => 'newsletter']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(Utility::contentHtml())->toContain('The route “newsletter” belongs to the project.');
});

it('warns about a sender domain that is not verified', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);

	$responses = TEAM_API_RESPONSES;
	$responses['/domains']['data'][0]['status'] = 'pending_verification';
	$this->http(teamApiResponses($responses));

	expect(Utility::contentHtml())->toContain('The domain “acme.test” of the system email address is not fully verified.');
});

it('reports a project that is not in the team', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'unknown']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(Utility::contentHtml())->toContain('The project “unknown” is not a project of the team.');
});

it('shows the sections that could be loaded', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(static fn(RequestInterface $request): Response => match ($request->getUri()->getPath()) {
		'/v1/team' => jsonResponse(TEAM_API_RESPONSES['/team']),
		'/v1/team/usage' => new Response(403),
		default => new Response(500),
	});

	expect(utilityHtml())
		->toContain('Acme Inc')
		->toContain('This could not be loaded from Lettermint.');
});

it('reports a section the team token is not allowed to read', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);

	$this->http(static fn(RequestInterface $request) => $request->getUri()->getPath() === '/v1/stats'
		? new Response(403)
		: teamApiResponses(TEAM_API_RESPONSES)($request));

	expect(Utility::contentHtml())->toContain('The team API token is not allowed to read this.');
});

it('marks a team that is not verified', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	$responses = TEAM_API_RESPONSES;
	$responses['/team']['verified_at'] = null;
	$this->http(teamApiResponses($responses));

	expect(Utility::contentHtml())->toContain('<span class="lettermint-badge lettermint-warning">Not verified</span>');
});

it('charts the emails sent per day', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);

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

it('colours poor rates', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);

	$responses = TEAM_API_RESPONSES;
	$responses['/stats']['totals'] = ['sent' => 100, 'delivered' => 80, 'hard_bounced' => 6, 'spam_complaints' => 1];
	$this->http(teamApiResponses($responses));

	expect(Utility::contentHtml())
		->toContain('<dd class="lettermint-tile-note lettermint-tone-poor">80.0%</dd>')
		->toContain('<dd class="lettermint-tile-note lettermint-tone-poor">6.0%</dd>')
		->toContain('<dd class="lettermint-tile-note lettermint-tone-poor">1.0%</dd>');
});

it('shows the status of the tokens', function (): void {
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

it('pings Lettermint with the project token', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$responses = teamApiResponses(TEAM_API_RESPONSES);
	$this->http(static function (RequestInterface $request) use ($responses): ResponseInterface {
		if ($request->getUri()->getPath() === PING_PATH) {
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

it('shows the status of the project token without a team token', function (): void {
	lettermintEmail(['token' => 'secret']);
	$this->http(new Response(200, [], 'pong'));

	expect(Utility::contentHtml())
		->toContain('Set a team API token in the email settings')
		->toContain('<span class="lettermint-pill lettermint-pill-green">Valid</span>')
		->toContain('<span class="lettermint-pill lettermint-pill-gray">Not set</span>');
});

it('reports a team token that could not be checked', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(static fn(RequestInterface $request): Response => $request->getUri()->getPath() === PING_PATH
		? new Response(200, [], 'pong')
		: new Response(503));

	expect(Utility::contentHtml())
		->toContain('<span class="lettermint-pill lettermint-pill-gray">Not checked</span>')
		->toContain('Lettermint could not be reached to check the token.');
});

it('takes a team token that is not allowed to read the team as valid', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(static fn(RequestInterface $request): Response => $request->getUri()->getPath() === PING_PATH
		? new Response(200, [], 'pong')
		: new Response(403));

	expect(substr_count(Utility::contentHtml(), 'lettermint-pill lettermint-pill-green">Valid'))->toBe(2);
});

it('shows the dates of a current period', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(Utility::contentHtml())->toContain('1/1/2020 – 12/31/2099');
});

it('shows the usage of a period that has passed without its dates', function (): void {
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

it('shows a full usage as exceeded', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	$responses = TEAM_API_RESPONSES;
	$responses['/team/usage']['current_period']['usage'] = 60000;
	$this->http(teamApiResponses($responses));

	expect(Utility::contentHtml())
		->toContain('lettermint-meter error')
		->toContain('max="50000" value="60000"');
});

it('warns about the usage from the share the health checks warn from', function (int $used, bool $warns): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	$responses = TEAM_API_RESPONSES;
	$responses['/team/usage']['current_period']['usage'] = $used;
	$this->http(teamApiResponses($responses));

	$html = Utility::contentHtml();

	$warns
		? expect($html)->toContain('lettermint-meter warning')
		: expect($html)->not->toContain('lettermint-meter warning');
})->with([
	'below' => [44999, false],
	'at' => [45000, true],
]);

it('keeps the project and its domains out of the sections', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	$data = Utility::data();

	expect(assertArray($data['sections']))->not->toHaveKeys(['project', 'domains']);
	expect(assertArray($data['project'])['id'])->toBe('project-1');
});

it('checks no project token that is not set', function (): void {
	lettermintEmail(['teamToken' => 'team-secret']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(Utility::data()['tokens'])->toBe(['token' => null, 'teamToken' => 'valid']);
});
