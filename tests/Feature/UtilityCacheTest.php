<?php

declare(strict_types=1);

use craft\enums\CmsEdition;
use craft\mail\transportadapters\Sendmail;
use craft\web\View;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\enums\Setup;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\UtilityData;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\services\StatusService;
use SomehowDigital\Craft\Lettermint\utilities\LettermintUtility;
use SomehowDigital\Craft\Lettermint\widgets\LettermintWidget;
use yii\i18n\MessageSource;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * Simulates a new request, keeping the cache.
 */
function forgetUtilityData(): void
{
	plugin()->set('status', Plugin::config()['components']['status']);
}

/**
 * Caches stale data that asks for a team token.
 */
function cacheStaleData(): void
{
	$status = plugin()->getStatus();
	$settings = assertInstance($status->settings(), Settings::class);
	$data = UtilityData::from($settings, null, null, new DateTimeImmutable(sprintf('-%d seconds', StatusService::FRESH_DURATION + 1)));

	Craft::$app->getCache()?->set($status->cacheKey($settings, 'utility'), $data, StatusService::STALE_DURATION);
}

beforeEach(function (): void {
	edition(Edition::Pro);
	Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
	signIn();

	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$this->requests = $this->http(teamApiResponses(TEAM_API_RESPONSES));
});

it('caches the data for the utility and the widget', function (): void {
	utilityHtml();
	$count = count($this->requests);
	forgetUtilityData();

	widgetHtml();
	forgetUtilityData();
	utilityHtml();

	expect($count)->toBeGreaterThan(0);
	expect($this->requests)->toHaveCount($count);
});

it('loads the data again right away for other settings', function (): void {
	utilityHtml();
	$count = count($this->requests);
	forgetUtilityData();

	lettermintEmail(['token' => 'secret', 'teamToken' => 'other-secret', 'projectId' => 'project-1']);
	utilityHtml();

	expect($this->requests)->toHaveCount($count * 2);
});

it('loads the data again for another version of the plugin, which may shape it differently', function (): void {
	utilityHtml();
	$count = count($this->requests);
	forgetUtilityData();

	Craft::$container->set(StatusService::class, [...assertArray(Craft::$container->getDefinitions()[StatusService::class]), 'version' => '99.0.0']);
	forgetUtilityData();
	utilityHtml();

	expect($this->requests)->toHaveCount($count * 2);
});

it('loads the data again once it is no longer fresh', function (): void {
	cacheStaleData();

	expect(utilityContent())->toContain('Acme Inc');
	expect($this->requests)->toHaveCount(6);
});

it('does not keep data that Lettermint could not be reached for', function (string $path): void {
	$this->requests = $this->http(static fn(RequestInterface $request): ResponseInterface => $request->getUri()->getPath() === $path
		? new GuzzleResponse(503)
		: teamApiResponses(TEAM_API_RESPONSES)($request));

	utilityContent();
	$count = count($this->requests);
	forgetUtilityData();

	expect(LettermintUtility::contentHtml())->toContain('lettermint-skeleton');
	utilityContent();

	expect($this->requests)->toHaveCount($count * 2);
})->with([
	'the ping of the project token' => ['/v1/ping'],
	'a result of the team API' => ['/v1/stats'],
]);

it('keeps data with the answers of Lettermint, also the ones it refused', function (): void {
	$this->requests = $this->http(static fn(RequestInterface $request): ResponseInterface => $request->getUri()->getPath() === '/v1/stats'
		? new GuzzleResponse(403)
		: teamApiResponses(TEAM_API_RESPONSES)($request));

	utilityContent();
	$count = count($this->requests);
	forgetUtilityData();
	utilityContent();

	expect($this->requests)->toHaveCount($count);
});

it('takes data as fresh for the minutes of the cache', function (): void {
	$now = new DateTimeImmutable('2026-11-07 12:00:00');
	$utility = plugin()->getStatus();
	$data = static fn(string $modifier): UtilityData => UtilityData::from(null, null, null, $now->modify($modifier));

	expect($utility->isFresh($data('-299 seconds'), $now))->toBeTrue();
	expect($utility->isFresh($data('-300 seconds'), $now))->toBeFalse();
});

it('loads the data again with a post request, as the refresh button does', function (string $action, string $part): void {
	utilityHtml();
	$count = count($this->requests);

	expect(utilityAction($action, 'POST'))->toHaveKey($part);
	expect($this->requests)->toHaveCount($count * 2);
})->with([
	'utility' => [LettermintUtility::ACTION, 'content'],
	'widget' => [LettermintWidget::ACTION, 'widget'],
]);

it('returns the parts that replace the ones of the page', function (): void {
	$utility = utilityAction(LettermintUtility::ACTION);
	$widget = utilityAction(LettermintWidget::ACTION);

	expect(array_keys($utility))->toBe(['content', 'footer']);
	expect(array_keys($widget))->toBe(['widget']);

	expect($utility['content'])
		->toContain('data-lettermint-part="content"')
		->not->toContain('aria-busy')
		->not->toContain('lettermint-skeleton');
	expect($utility['footer'])
		->toContain('data-lettermint-part="footer"')
		->toContain('data-lettermint-refresh="lettermint/utility/content"');
	expect($widget['widget'])
		->toContain('data-lettermint-part="widget"')
		->toContain('data-lettermint-refresh="lettermint/utility/widget"');
});

it('shows placeholder data in the layout of the data while nothing is cached', function (): void {
	$content = LettermintUtility::contentHtml();
	$footer = LettermintUtility::footerHtml();

	expect($this->requests)->toHaveCount(0);

	expect($content)
		->toContain('class="lettermint-utility lettermint-skeleton" data-lettermint-part="content" data-lettermint-placeholder aria-busy="true"')
		->toContain('<p class="visually-hidden" role="status">Loading from Lettermint…</p>')
		->toContain('<template data-lettermint-error>')
		->toContain('This could not be loaded from Lettermint.')
		->toContain('<div class="lettermint-skeleton-body" inert>')
		// The placeholder layout, with the settings' project.
		->toContain('lettermint-hero')
		->toContain('id="lettermint-tokens"')
		->toContain('<code>project-1</code>')
		->toMatch('#<dt>Default route</dt>\s*<dd><code>outgoing</code></dd>#')
		->toContain('Last 28 days')
		->toContain('Emails sent per day')
		->toContain('1 domain');

	expect($footer)
		->toContain('class="lettermint-footer lettermint-skeleton"')
		->toContain('Refresh')
		->not->toContain('role="status"');

	expect(registeredJs())->toBe('LettermintUtility.load("content", "lettermint\/utility\/content");');
});

it('registers the translation of the error for the script', function (): void {
	$translations = Craft::$app->getI18n()->translations;
	Craft::$app->getI18n()->translations['lettermint'] = new class () extends MessageSource {
		/**
		 * @return array<string,string>
		 */
		protected function loadMessages($category, $language): array
		{
			return $language === 'de-AT' ? ['This could not be loaded from Lettermint.' => 'Das konnte nicht von Lettermint geladen werden.'] : [];
		}
	};

	try {
		Craft::$app->language = 'de-AT';
		LettermintUtility::contentHtml();

		expect(registeredJs())->toContain('Craft.translations["lettermint"]["This could not be loaded from Lettermint."] = "Das konnte nicht von Lettermint geladen werden.";');
	} finally {
		Craft::$app->getI18n()->translations = $translations;
		Craft::$app->language = 'en-US';
	}
});

it('shows placeholder data without a project in the layout without one', function (): void {
	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret']);

	expect(LettermintUtility::contentHtml())
		->toContain('lettermint-skeleton')
		->toContain('lettermint-hero')
		->not->toContain('Last 28 days');
});

it('shows placeholder data without a team token in the layout without one', function (): void {
	lettermintEmail(['token' => 'secret']);

	expect(LettermintUtility::contentHtml())
		->toContain('lettermint-skeleton')
		->toContain('Set a team API token in the email settings')
		->not->toContain('lettermint-hero');
});

it('shows placeholder data in the widget while nothing is cached', function (): void {
	expect((new LettermintWidget())->getBodyHtml())
		->toContain('class="lettermint-utility lettermint-widget lettermint-skeleton" data-lettermint-part="widget" data-lettermint-placeholder aria-busy="true"')
		->toContain('Last 28 days');

	expect(registeredJs())->toBe('LettermintUtility.load("widget", "lettermint\/utility\/widget");');
	expect($this->requests)->toHaveCount(0);
});

it('shows data that is no longer fresh while it is loaded again', function (): void {
	cacheStaleData();

	expect(LettermintUtility::contentHtml())
		->toContain('class="lettermint-utility" data-lettermint-part="content" aria-busy="true"')
		->toContain('Set a team API token in the email settings')
		->not->toContain('lettermint-skeleton')
		->not->toContain('Loading from Lettermint…');
	expect((new LettermintWidget())->getBodyHtml())
		->toContain('class="lettermint-utility lettermint-widget" data-lettermint-part="widget" aria-busy="true"');

	expect(registeredJs())
		->toContain('LettermintUtility.load("content"')
		->toContain('LettermintUtility.load("widget"');
	expect($this->requests)->toHaveCount(0);
});

it('shows fresh data without loading it again', function (): void {
	utilityHtml();
	$count = count($this->requests);
	forgetUtilityData();

	expect(LettermintUtility::contentHtml())
		->toContain('<h2 id="lettermint-overview" class="lettermint-hero-title">Acme Inc</h2>')
		->not->toContain('aria-busy');
	expect(LettermintUtility::footerHtml())
		->toContain('Loaded from Lettermint at')
		->not->toContain('aria-busy');
	expect((new LettermintWidget())->getBodyHtml())->toContain('Acme Inc');

	expect(registeredJs())->toBe('');
	expect($this->requests)->toHaveCount($count);
});

it('shows the data right away without Lettermint as the transport, as there is nothing to load', function (): void {
	Craft::$app->getProjectConfig()->set('email', ['fromEmail' => 'hello@acme.test', 'transportType' => Sendmail::class]);

	expect(LettermintUtility::contentHtml())->toContain('Lettermint is not the transport type of the email settings.');
	expect((new LettermintWidget())->getBodyHtml())->toContain('Lettermint is not the transport type of the email settings.');
	expect(trim(LettermintUtility::footerHtml()))->toBe('<div class="lettermint-footer" data-lettermint-part="footer"></div>');
	expect(registeredJs())->toBe('');
});

it('keeps the data for the request without a cache', function (): void {
	withoutCache();

	utilityHtml();
	$count = count($this->requests);
	plugin()->getStatus()->data();

	expect($count)->toBeGreaterThan(0);
	expect($this->requests)->toHaveCount($count);
});

it('shows placeholder data and loads the data again without a cache', function (): void {
	withoutCache();

	utilityHtml();
	$count = count($this->requests);
	forgetUtilityData();

	expect(LettermintUtility::contentHtml())->toContain('lettermint-skeleton');

	plugin()->getStatus()->reload();

	expect($this->requests)->toHaveCount($count * 2);
});

it('loads the data again without Lettermint as the transport', function (): void {
	Craft::$app->getProjectConfig()->set('email', ['fromEmail' => 'hello@acme.test', 'transportType' => Sendmail::class]);

	expect(plugin()->getStatus()->data()->setup)->toBe(Setup::Transport);
	expect(plugin()->getStatus()->reload()->setup)->toBe(Setup::Transport);
});

it('only loads the data for users who may open the utility', function (string $action): void {
	// Craft Solo grants all permissions.
	Craft::$app->edition = CmsEdition::Pro;
	signIn(admin: false);

	utilityAction($action);
})->with([LettermintUtility::ACTION, LettermintWidget::ACTION])->throws(ForbiddenHttpException::class);

it('loads no data in the lite edition', function (string $action): void {
	edition(Edition::Lite);
	signIn();

	utilityAction($action);
})->with([LettermintUtility::ACTION, LettermintWidget::ACTION])->throws(ForbiddenHttpException::class);

it('only loads the data from the control panel', function (string $action): void {
	webRequest()->setIsCpRequest(false);

	Craft::$app->runAction($action);
})->with([LettermintUtility::ACTION, LettermintWidget::ACTION])->throws(BadRequestHttpException::class);
