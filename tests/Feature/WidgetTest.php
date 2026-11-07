<?php

declare(strict_types=1);

use craft\enums\CmsEdition;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Dashboard;
use craft\web\View;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SomehowDigital\Craft\Lettermint\Edition;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;
use SomehowDigital\Craft\Lettermint\Widget;

/**
 * Replaces the dashboard with one that tells whether the user has the widget already, without the database.
 */
function dashboardWithWidget(bool $has): void
{
	Craft::$app->set('dashboard', new class ($has) extends Dashboard {
		public function __construct(
			private readonly bool $has
		) {
			parent::__construct();
		}

		public function doesUserHaveWidget(string $type): bool
		{
			return $this->has;
		}
	});
}

beforeEach(function (): void {
	Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
	plugin()->edition = Edition::Pro->value;
	signIn();
	dashboardWithWidget(false);
});

it('is named Lettermint', function (): void {
	expect(Widget::displayName())->toBe('Lettermint');
	expect(Widget::icon())->toBeFile();
});

it('is registered as a widget type', function (): void {
	$event = new RegisterComponentTypesEvent(['types' => []]);
	Craft::$app->getDashboard()->trigger(Dashboard::EVENT_REGISTER_WIDGET_TYPES, $event);

	expect($event->types)->toContain(Widget::class);
});

it('is only available in the pro edition', function (): void {
	expect(Widget::isSelectable())->toBeTrue();

	plugin()->edition = Edition::Standard->value;

	expect(Widget::isSelectable())->toBeFalse();
	expect((new Widget())->getBodyHtml())->toBeNull();
});

it('can only be added once', function (): void {
	dashboardWithWidget(true);

	expect(Widget::isSelectable())->toBeFalse();
});

it('is only available to users who may open the utility', function (): void {
	// Users of Craft Solo may do everything.
	Craft::$app->edition = CmsEdition::Pro;
	signIn(admin: false);

	expect(Widget::isSelectable())->toBeFalse();
	expect((new Widget())->getBodyHtml())->toBeNull();
});

it('asks for Lettermint as the transport', function (): void {
	expect((new Widget())->getBodyHtml())
		->toContain('Lettermint is not the transport type of the email settings.')
		->toContain('settings/email')
		->toContain('utilities/lettermint')
		->not->toContain('Loaded from Lettermint at');
});

it('registers the styles of the utility', function (): void {
	(new Widget())->getBodyHtml();

	expect(Craft::$app->getView()->assetBundles)->toHaveKey(UtilityAsset::class);
});

it('reports a rejected team token', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(new Response(401));

	expect((new Widget())->getBodyHtml())
		->toContain('Lettermint rejects the team API token.')
		->not->toContain('Usage');
});

it('shows the usage of the team, and the statistics and checks of the project', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect((new Widget())->getBodyHtml())
		// Team
		->toContain('Acme Inc')
		->toContain('Growth plan')
		->toContain('Sandbox')
		// Usage
		->toContain('12,500')
		->toContain('/ 50,000 per month')
		->toContain('max="50000" value="12500"')
		// Statistics
		->toContain('Last 28 days')
		->toContain('<dd class="lettermint-tile-note lettermint-tone-good">98.0%</dd>')
		->toContain('<dd class="lettermint-tile-note lettermint-tone-good">1.5%</dd>')
		->not->toContain('Opened')
		// Checks, of which only the ones that fail are listed.
		->toContain('Checks')
		->toContain('lettermint-checks')
		->not->toContain('All checks passed')
		// Footer
		->toContain('Loaded at')
		->toContain('lettermint/utility/refresh')
		->toContain('Open utility');
});

it('reports a project token that does not pass its check', function (): void {
	lettermintEmail(['teamToken' => 'team-secret']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect((new Widget())->getBodyHtml())
		->toContain('<div class="lettermint-callout lettermint-error">')
		->toContain('The project API token is not set.');
});

it('reports no passing project token', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect((new Widget())->getBodyHtml())->not->toContain('Lettermint accepts the project API token.');
});

it('shows no statistics without a project', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	expect((new Widget())->getBodyHtml())
		->toContain('12,500')
		->not->toContain('Last 28 days');
});

it('sums up passing checks', function (): void {
	$responses = TEAM_API_RESPONSES;
	$responses['/projects/project-1']['delivery_mode'] = 'live';
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);
	$this->http(teamApiResponses($responses));

	expect((new Widget())->getBodyHtml())
		->toContain('All checks passed');
});

it('shows a chart of the emails sent per day', function (): void {
	$responses = TEAM_API_RESPONSES;
	$responses['/stats']['daily'] = [
		['date' => '2026-11-03', 'sent' => 10],
		['date' => '2026-11-04', 'sent' => 0],
	];
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);
	$this->http(teamApiResponses($responses));

	expect((new Widget())->getBodyHtml())
		->toContain('Emails sent per day')
		->toContain('--lettermint-bar: 100%')
		->toContain('lettermint-chart-bar-empty');
});

it('reports a missing project', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-9']);
	$this->http(static fn(RequestInterface $request) => match ($request->getUri()->getPath()) {
		'/v1/projects/project-9' => new Response(404),
		default => teamApiResponses(TEAM_API_RESPONSES)($request),
	});

	expect((new Widget())->getBodyHtml())
		->toContain('The project “project-9” is not a project of the team.');
});
