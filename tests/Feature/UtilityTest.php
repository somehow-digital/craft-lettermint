<?php

declare(strict_types=1);

use craft\events\RegisterComponentTypesEvent;
use craft\helpers\UrlHelper;
use craft\services\Utilities;
use craft\web\View;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\enums\CheckType;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\models\Check;
use SomehowDigital\Craft\Lettermint\utilities\LettermintUtility;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;

/** Lettermint's ping path. */
const PING_PATH = '/v1/ping';

beforeEach(function (): void {
	edition(Edition::Pro);
	Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
	signIn();
});

it('is named Lettermint', function (): void {
	expect(LettermintUtility::id())->toBe('lettermint');
	expect(LettermintUtility::displayName())->toBe('Lettermint');
	// Not a directory.
	expect(is_file(LettermintUtility::icon()))->toBeTrue();
	expect(LettermintUtility::icon())->toEndWith('.svg');
});

it('is only available in the pro edition', function (): void {
	// Read from the event, as Craft's utility list queries the volumes.
	$event = new RegisterComponentTypesEvent(['types' => []]);
	Craft::$app->getUtilities()->trigger(Utilities::EVENT_REGISTER_UTILITIES, $event);

	expect($event->types)->toContain(LettermintUtility::class);
	expect(LettermintUtility::isSelectable())->toBeTrue();

	edition(Edition::Standard);

	expect(LettermintUtility::isSelectable())->toBeFalse();
});

it('asks for Lettermint as the transport', function (): void {
	expect(utilityHtml())
		->toContain('Lettermint is not the transport type of the email settings.')
		->toContain('settings/email')
		->not->toContain('Refresh');
});

it('registers the styles of the utility', function (): void {
	LettermintUtility::contentHtml();

	$bundle = assertInstance(Craft::$app->getView()->assetBundles[UtilityAsset::class] ?? null, UtilityAsset::class);

	expect($bundle->css)->toBe(['utility.css']);
	expect($bundle->js)->toBe(['utility.js']);
	expect(assertString($bundle->sourcePath) . '/utility.css')->toBeFile();
	expect(assertString($bundle->sourcePath) . '/utility.js')->toBeFile();
});

it('asks for a team token', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => '$LETTERMINT_MISSING']);
	$this->http(new Response(200, [], 'pong'));

	expect(utilityContent())->toContain('Set a team API token in the email settings');
});

it('reports a rejected team token', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(new Response(401));

	expect(utilityHtml())
		->toContain('Lettermint rejects the team API token.')
		->toContain('id="lettermint-tokens"')
		->not->toContain('Usage')
		// Only a refresh would show the token as accepted again.
		->toContain('Refresh');
});

it('loads the data from Lettermint once for the content and footer', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$requests = $this->http(teamApiResponses(TEAM_API_RESPONSES));

	utilityHtml();

	// Team, usage, project, domains, statistics, and the project token ping.
	expect($requests)->toHaveCount(6);
});

it('links the health endpoint with its token', function (): void {
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(utilityContent())
		->toContain('href="' . htmlspecialchars(UrlHelper::siteUrl('actions/lettermint/health', ['key' => HEALTH_TOKEN])) . '"')
		->toContain('Health Endpoint');
});

it('links no health endpoint without a health token', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(utilityContent())
		->toContain('<h3>Health</h3>')
		->not->toContain('Health Endpoint');
});

it('links the health endpoint only for admins', function (): void {
	// Craft Solo grants all permissions.
	signIn(admin: false);
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(utilityContent())
		->toContain('<h3>Health</h3>')
		->not->toContain('Health Endpoint')
		->not->toContain(HEALTH_TOKEN);
});

it('shows the settings without tokens and secrets', function (): void {
	// As editors see them. Craft Solo grants all permissions.
	signIn(admin: false);
	addSite('site-a', 'shop', 'Shop', primary: true);
	addSite('site-b', 'archive', 'Archive', enabled: false);
	// Unsaved sites have no settings.
	addSite('site-c', 'draft', 'Draft')->uid = null;
	Craft::$app->getProjectConfig()->set('email', [
		'fromEmail' => 'hello@acme.test',
		'fromName' => 'Acme',
		'transportType' => Adapter::class,
		'transportSettings' => [
			'token' => 'secret',
			'teamToken' => 'team-secret',
			'projectId' => 'project-1',
			'healthToken' => HEALTH_TOKEN,
			'route' => 'outgoing',
			'tags' => 'env=production',
			'sites' => ['site-b' => ['route' => 'newsletter', 'tags' => 'site=archive']],
		],
		'siteOverrides' => ['site-b' => ['fromEmail' => 'archive@acme.test', 'fromName' => 'Acme Archive']],
	]);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	$html = utilityContent();
	// A site's row, with whitespace collapsed.
	$row = static fn(string $site): string => (string) preg_replace('#\s+#', ' ', explode('</tr>', explode("<th scope=\"row\">{$site}</th>", $html)[1] ?? '')[0]);

	expect($html)
		->toContain('<h2 id="lettermint-settings">Settings</h2>')
		->toContain('Webhooks off · Health endpoint on')
		// Each site's own system email address is checked as well.
		->toContain('The domain “acme.test” of the system email address of Archive belongs to the project.')
		->not->toContain('Draft')
		->not->toContain('secret')
		->not->toContain(HEALTH_TOKEN);

	expect($row('All sites'))
		->toContain('<td>hello@acme.test</td> <td>Acme</td> <td>–</td> <td>–</td> <td><code>outgoing</code></td> <td><code>env=production</code></td>');

	expect($row('Shop'))
		->toContain('<td>hello@acme.test <span class="light">(inherited)</span></td>')
		->toContain('<td>Acme <span class="light">(inherited)</span></td> <td>–</td> <td>–</td>')
		->toContain('<td><code>outgoing</code> <span class="light">(inherited)</span></td>')
		->toContain('<td><code>env=production</code> <span class="light">(inherited)</span></td>');

	expect($row('Archive'))
		->toContain('<td>archive@acme.test</td> <td>Acme Archive</td> <td>–</td> <td>–</td> <td><code>newsletter</code></td> <td><code>env=production</code> <code>site=archive</code></td>');
});

it('shows the default route of the project without a route', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(utilityContent())
		->toContain('Webhooks off · Health endpoint off')
		->toMatch('#<td><span class="light">Default route of the project</span></td>\s*<td>–</td>#')
		->not->toContain('(inherited)');
});

it('shows the team of the team token', function (): void {
	$this->env(['LETTERMINT_TEAM_TOKEN' => 'team-secret']);
	lettermintEmail(['token' => 'secret', 'teamToken' => '$LETTERMINT_TEAM_TOKEN']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	['content' => $html, 'footer' => $footer] = utilityAction('lettermint/utility/content');

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
		// Project, domain and member counts.
		->not->toContain('<dt>Projects</dt>')
		->not->toContain('<dt>Domains</dt>')
		->not->toContain('<dt>Members</dt>')
		->not->toContain('team-secret');
});

it('requests nothing but the team without a project', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$requests = $this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(utilityContent())
		->toContain('Acme Inc')
		->toContain('Set the project of the project API token in the email settings')
		->not->toContain('Website')
		->not->toContain('lettermint-domains')
		->not->toContain('lettermint-stats');

	expect(array_map(static fn(RequestInterface $request): string => $request->getUri()->getPath(), $requests->getArrayCopy()))
		->toBe(['/v1/team', '/v1/team/usage', PING_PATH]);
});

it('shows the statistics and domains of the project only', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$requests = $this->http(teamApiResponses(TEAM_API_RESPONSES));

	$html = utilityContent();

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
		// Project domains, with statuses from the team's domains.
		->toContain('1 domain')
		->toContain('<th scope="row">acme.test</th>')
		->toContain('<span class="lettermint-pill lettermint-pill-green">Verified</span>')
		// Domains of other projects.
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
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	$html = utilityContent();

	expect($html)
		->toContain('<h2 id="lettermint-project">Project</h2>')
		->toContain('<h3>Website</h3>')
		->toContain('href="https://app.lettermint.co/projects/project-1"')
		->toContain('Lettermint Dashboard')
		->toContain('viewBox="0 0 252 255"')
		->toContain('<code>project-1</code>')
		->toContain('<code>outgoing</code>')
		->toContain('203.0.113.7')
		->toContain('The project is in sandbox mode, so emails are not delivered to their recipients.')
		->toContain('The domain “acme.test” of the system email address belongs to the project.')
		->toContain('Emails use the default route “outgoing” of the project.');
});

it('sums up the checks of the project in a badge', function (?string $route, string $deliveryMode, string $badge): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1', 'route' => $route]);

	$responses = TEAM_API_RESPONSES;
	$responses['/projects/project-1']['delivery_mode'] = $deliveryMode;
	$this->http(teamApiResponses($responses));

	expect(utilityContent())->toMatch('#<h3>Health</h3>\s*' . preg_quote($badge, '#') . '#');
})->with([
	'healthy' => [null, 'live', '<span class="lettermint-pill lettermint-pill-green">Healthy</span>'],
	'warning' => [null, 'sandbox', '<span class="lettermint-pill lettermint-pill-yellow">Warning</span>'],
	'error' => ['inbox', 'live', '<span class="lettermint-pill lettermint-pill-red">Error</span>'],
]);

it('shows the configuration of the project in Lettermint', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	$html = (string) preg_replace('#\s+#', ' ', utilityContent());
	$pill = static fn(string $tone, string $text): string => "<span class=\"lettermint-pill lettermint-pill-{$tone}\">{$text}</span>";

	expect($html)
		->toContain('<dt>Created</dt> <dd>1/15/2026</dd>')
		->toContain('<dt>Sandbox mode</dt> <dd> ' . $pill('orange', 'On') . ' </dd>')
		->toContain('<dt>SMTP</dt> <dd> ' . $pill('green', 'On') . ' </dd>')
		->toContain('<dt>Hide email content</dt> <dd> ' . $pill('gray', 'Off') . ' </dd>')
		->toContain('<dt>Open tracking</dt> <dd> ' . $pill('green', 'On') . ' </dd>')
		->toContain('<dt>Click tracking</dt> <dd> ' . $pill('gray', 'Off') . ' </dd>');
});

it('lists the routes of the project', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	$html = (string) preg_replace('#\s+#', ' ', utilityContent());
	$row = static fn(string $slug): string => explode('</tr>', explode("<th scope=\"row\"><code>{$slug}</code>", $html)[1] ?? '')[0];

	expect($html)
		->toContain('<h2 id="lettermint-routes">Routes</h2>')
		->toContain('3 routes');

	// With the default route first, as Lettermint lists them.
	expect($row('outgoing'))
		->toContain('<span class="lettermint-pill lettermint-pill-green">Default</span>')
		->toContain('<td>Transactional</td> <td> <span class="lettermint-pill lettermint-pill-green">On</span> </td> <td> <span class="lettermint-pill lettermint-pill-gray">Off</span> </td>');

	// Without route settings, tracking is unknown.
	expect($row('newsletter'))
		->not->toContain('Default')
		->toContain('<td>Broadcast</td> <td>–</td> <td>–</td>');
	expect($row('inbox'))->toContain('<td>Inbound</td>');
});

it('lists no route that the project does not have', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);

	$responses = TEAM_API_RESPONSES;
	$responses['/projects/project-1']['routes'] = [];
	$this->http(teamApiResponses($responses));

	expect(utilityContent())->toContain('The project has no routes yet.');
});

it('shows no configuration that Lettermint does not tell', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);

	$responses = TEAM_API_RESPONSES;
	unset($responses['/projects/project-1']['smtp_enabled'], $responses['/projects/project-1']['redact_email_content'], $responses['/projects/project-1']['created_at'], $responses['/projects/project-1']['delivery_mode'], $responses['/projects/project-1']['routes'][0]['settings']);
	$this->http(teamApiResponses($responses));

	$html = (string) preg_replace('#\s+#', ' ', utilityContent());

	expect($html)
		->toContain('<dt>Sandbox mode</dt> <dd>–</dd>')
		->toContain('<dt>SMTP</dt> <dd>–</dd>')
		->toContain('<dt>Hide email content</dt> <dd>–</dd>')
		->not->toContain('<dt>Created</dt>')
		->not->toContain('<dt>Open tracking</dt>')
		->not->toContain('<dt>Click tracking</dt>');
});

it('checks the routes and the sender domain against the project', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1', 'route' => 'inbox'], 'hello@other.test');
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(utilityContent())
		->toContain('The route “inbox” is not an outgoing route of the project.')
		->toContain('The domain “other.test” of the system email address is not a domain of the project.');
});

it('checks a configured route that belongs to the project', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1', 'route' => 'newsletter']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(utilityContent())->toContain('The route “newsletter” belongs to the project.');
});

it('warns about a sender domain that is not verified', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);

	$responses = TEAM_API_RESPONSES;
	$responses['/domains']['data'][0]['status'] = 'pending_verification';
	$this->http(teamApiResponses($responses));

	expect(utilityContent())->toContain('The domain “acme.test” of the system email address is not fully verified.');
});

it('reports a project that is not in the team', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'unknown']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(utilityContent())->toContain('The project “unknown” is not a project of the team.');
});

it('shows the results that could be loaded', function (): void {
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

it('reports a result the team token is not allowed to read', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);

	$this->http(static fn(RequestInterface $request) => $request->getUri()->getPath() === '/v1/stats'
		? new Response(403)
		: teamApiResponses(TEAM_API_RESPONSES)($request));

	expect(utilityContent())->toContain('The team API token is not allowed to read this.');
});

it('marks a team that is not verified', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	$responses = TEAM_API_RESPONSES;
	$responses['/team']['verified_at'] = null;
	$this->http(teamApiResponses($responses));

	expect(utilityContent())->toContain('<span class="lettermint-badge lettermint-warning">Not verified</span>');
});

it('charts the emails sent per day', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);

	$responses = TEAM_API_RESPONSES;
	$responses['/stats']['daily'] = [
		['date' => '2026-11-02', 'sent' => 40],
		['date' => '2026-11-03', 'sent' => 0],
		['date' => '2026-11-04', 'sent' => 10],
	];
	$this->http(teamApiResponses($responses));

	expect(utilityContent())
		->toContain('Emails sent per day')
		->toContain('Peak of 40')
		->toContain('style="--lettermint-bar: 100%"')
		->toContain('class="lettermint-chart-bar lettermint-chart-bar-empty"')
		->toContain('style="--lettermint-bar: 25%"');
});

it('colours poor rates', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);

	$responses = TEAM_API_RESPONSES;
	$responses['/stats']['totals'] = ['sent' => 100, 'delivered' => 80, 'hard_bounced' => 6, 'spam_complaints' => 1];
	$this->http(teamApiResponses($responses));

	expect(utilityContent())
		->toContain('<dd class="lettermint-tile-note lettermint-tone-poor">80.0%</dd>')
		->toContain('<dd class="lettermint-tile-note lettermint-tone-poor">6.0%</dd>')
		->toContain('<dd class="lettermint-tile-note lettermint-tone-poor">1.0%</dd>');
});

it('shows the status of the tokens', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	$html = utilityContent();

	expect($html)
		->toContain('<h2 id="lettermint-tokens">API Tokens</h2>')
		->toContain('<p class="lettermint-token-name">Project API Token</p>')
		->toContain('The project API token, which sends the emails.')
		->toContain('The team API token, which this page is loaded with.')
		->not->toContain('<span class="lettermint-pill lettermint-pill-red">Error</span>');
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

	expect(utilityContent())
		->toContain('<span class="lettermint-pill lettermint-pill-red">Error</span>')
		->toContain('Lettermint rejects the project API token, or does not allow it from the IP address of this server.')
		->toContain('<h2 id="lettermint-overview" class="lettermint-hero-title">Acme Inc</h2>');
});

it('shows the status of the project token without a team token', function (): void {
	lettermintEmail(['token' => 'secret']);
	$this->http(new Response(200, [], 'pong'));

	expect(utilityContent())
		->toContain('Set a team API token in the email settings')
		->toContain('<span class="lettermint-pill lettermint-pill-green">Valid</span>')
		->toContain('<span class="lettermint-pill lettermint-pill-gray">Not set</span>');
});

it('reports a team token that could not be checked', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(static fn(RequestInterface $request): Response => $request->getUri()->getPath() === PING_PATH
		? new Response(200, [], 'pong')
		: new Response(503));

	expect(utilityContent())
		->toContain('<span class="lettermint-pill lettermint-pill-yellow">Warning</span>')
		->toContain('The team could not be loaded from Lettermint.');
});

it('takes a team token that is not allowed to read the team as valid', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(static fn(RequestInterface $request): Response => $request->getUri()->getPath() === PING_PATH
		? new Response(200, [], 'pong')
		: new Response(403));

	expect(substr_count(utilityContent(), 'lettermint-pill lettermint-pill-green">Valid'))->toBe(2);
});

it('shows the dates of a current period', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect(utilityContent())->toContain('1/1/2020 – 12/31/2099');
});

it('shows the usage of a period that has passed without its dates', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	// Lettermint may report a past period's dates.
	$responses = TEAM_API_RESPONSES;
	$responses['/team/usage'] = [
		'current_period' => ['usage' => 80, 'period_start' => '2025-12-11T00:00:00Z', 'period_end' => '2026-01-11T00:00:00Z'],
		'historical_usage' => [],
	];
	$this->http(teamApiResponses($responses));

	expect(utilityContent())
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

	expect(utilityContent())
		->toContain('lettermint-meter error')
		->toContain('max="50000" value="60000"');
});

it('warns about the usage from the share the health checks warn from', function (int $used, bool $warns): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	$responses = TEAM_API_RESPONSES;
	$responses['/team/usage']['current_period']['usage'] = $used;
	$this->http(teamApiResponses($responses));

	$html = utilityContent();

	$warns
		? expect($html)->toContain('lettermint-meter warning')
		: expect($html)->not->toContain('lettermint-meter warning');
})->with([
	'below' => [44999, false],
	'at' => [45000, true],
]);

it('checks no project token that is not set', function (): void {
	lettermintEmail(['teamToken' => 'team-secret']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	$data = plugin()->getStatus()->data();

	expect($data->token)->toEqual(Check::error(CheckType::Token, 'The project API token is not set.'));
	expect($data->teamToken)->toEqual(Check::ok(CheckType::TeamToken, 'Lettermint accepts the team API token.'));
});
