<?php

declare(strict_types=1);

use craft\base\Model;
use craft\events\TemplateEvent;
use craft\web\View;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Lettermint\SymfonyMailer\Transport\LettermintApiTransport;
use Lettermint\SymfonyMailer\Transport\PayloadBuilder;
use Psr\Http\Message\RequestInterface;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Health;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\TokenStatus;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;
use SomehowDigital\Craft\Lettermint\Webhooks;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use yii\base\Event;

/** Environment variables, tags and markup the tests share. */
const TOKEN_VARIABLE = '$LETTERMINT_TOKEN';
const MISSING_VARIABLE = '$LETTERMINT_MISSING';
const TAGS_VARIABLE = '$LETTERMINT_TAGS';
const TEAM_TOKEN_VARIABLE = '$LETTERMINT_TEAM_TOKEN';
const WEBHOOK_SECRET_VARIABLE = '$LETTERMINT_WEBHOOK_SECRET';
const PROJECT_VARIABLE = '$LETTERMINT_PROJECT';
const SITE_TAGS = 'site=a';
const PROJECT_FIELD = 'id="project-field"';

/**
 * The value of a private property of an object.
 */
function privateProperty(object $object, string $property): mixed
{
	return (new ReflectionProperty($object, $property))->getValue($object);
}

/**
 * The Lettermint API transport of the adapter.
 */
function transport(Adapter $adapter): LettermintApiTransport
{
	return assertInstance($adapter->defineTransport(), LettermintApiTransport::class);
}

/**
 * The project token of the transport.
 */
function transportToken(LettermintApiTransport $transport): string
{
	return assertString(privateProperty($transport, 'projectToken'));
}

/**
 * The default message options of the transport.
 *
 * @return array<mixed>
 */
function transportOptions(LettermintApiTransport $transport): array
{
	return assertArray(privateProperty(assertInstance(privateProperty($transport, 'payloadBuilder'), PayloadBuilder::class), 'defaults'));
}

/**
 * The default request options of the HTTP client of the transport, such as its proxy.
 *
 * @return array<mixed>
 */
function transportClientOptions(LettermintApiTransport $transport): array
{
	$client = assertInstance((new ReflectionProperty(AbstractHttpTransport::class, 'client'))->getValue($transport), HttpClientInterface::class);

	return assertArray(privateProperty($client, 'defaultOptions'));
}

/**
 * The event dispatcher of the transport, which applies the metadata and the site routes.
 */
function transportDispatcher(LettermintApiTransport $transport): ?EventDispatcher
{
	$dispatcher = (new ReflectionProperty(AbstractTransport::class, 'dispatcher'))->getValue($transport);

	return $dispatcher !== null ? assertInstance($dispatcher, EventDispatcher::class) : null;
}

/**
 * The JS that the view registered, such as the props of the inputs that Vue renders.
 */
function registeredJs(): string
{
	$js = [];

	foreach (Craft::$app->getView()->js as $scripts) {
		foreach (assertArray($scripts) as $script) {
			$js[] = assertString($script);
		}
	}

	return implode("\n", $js);
}

it('is named Lettermint', function (): void {
	expect(Adapter::displayName())->toBe('Lettermint');
});

it('labels its attributes', function (): void {
	expect((new Adapter())->attributeLabels())->toBe([
		'token' => 'Token',
		'teamToken' => 'Team Token',
		'project' => 'Project',
		'webhookSecret' => 'Webhook Secret',
		'healthToken' => 'Health Token',
		'route' => 'Route',
		'tags' => 'Tags',
		'sites' => 'Sites',
	]);
});

it('has no token or route by default', function (): void {
	$adapter = new Adapter();

	expect($adapter->token)->toBeNull();
	expect($adapter->teamToken)->toBeNull();
	expect($adapter->route)->toBeNull();
	expect($adapter->tags)->toBeNull();
	expect($adapter->sites)->toBe([]);
});

describe('validation', function (): void {
	it('requires a token', function (): void {
		$adapter = new Adapter();

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('token');
		expect($adapter->getErrors())->not->toHaveKey('route');
	});

	it('passes with a token and without a route', function (): void {
		$adapter = new Adapter(['token' => 'secret']);

		expect($adapter->validate())->toBeTrue();
	});

	it('passes with a token and a route', function (): void {
		$adapter = new Adapter(['token' => 'secret', 'route' => 'outgoing']);

		expect($adapter->validate())->toBeTrue();
	});

	it('trims the token and the route', function (): void {
		$adapter = new Adapter(['token' => '  secret ', 'route' => ' outgoing  ']);

		expect($adapter->validate())->toBeTrue();
		expect($adapter->token)->toBe('secret');
		expect($adapter->route)->toBe('outgoing');
	});

	it('rejects a token that is only whitespace', function (): void {
		$adapter = new Adapter(['token' => '   ']);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('token');
	});

	it('validates a token referencing a set environment variable', function (): void {
		$this->env(['LETTERMINT_TOKEN' => 'secret']);

		$adapter = new Adapter(['token' => TOKEN_VARIABLE]);

		expect($adapter->validate())->toBeTrue();
		expect($adapter->token)->toBe(TOKEN_VARIABLE);
	});

	it('rejects a token referencing a missing environment variable', function (): void {
		$adapter = new Adapter(['token' => MISSING_VARIABLE]);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('token');
		expect($adapter->token)->toBe(MISSING_VARIABLE);
	});

	it('trims the team token', function (): void {
		$adapter = new Adapter(['token' => 'secret', 'teamToken' => ' team-secret  ']);

		expect($adapter->validate())->toBeTrue();
		expect($adapter->teamToken)->toBe('team-secret');
	});

	it('trims the sites and drops the ones without a route', function (): void {
		// Craft stores the posted settings as they are, without validating them first.
		$adapter = new Adapter(['token' => 'secret', 'sites' => [
			'site-a' => ['heading' => 'Site A', 'route' => ' outgoing '],
			'site-b' => ['route' => '  '],
			'site-c' => ['route' => ['invalid']],
			'site-d' => 'invalid',
		]]);

		expect($adapter->validate())->toBeTrue();
		expect($adapter->sites)->toBe([
			'site-a' => ['route' => 'outgoing'],
		]);
	});

	it('keeps the sites with tags', function (): void {
		$adapter = new Adapter(['token' => 'secret', 'sites' => [
			'site-a' => ['route' => '', 'tags' => ' site=a '],
			'site-b' => ['route' => 'shop-b', 'tags' => 'site=b'],
			'site-c' => ['route' => '', 'tags' => ' '],
		]]);

		expect($adapter->validate())->toBeTrue();
		expect($adapter->sites)->toBe([
			'site-a' => ['tags' => SITE_TAGS],
			'site-b' => ['route' => 'shop-b', 'tags' => 'site=b'],
		]);
	});

	it('passes with valid tags', function (): void {
		$adapter = new Adapter(['token' => 'secret', 'tags' => ' environment=production, campaign = welcome-v2 ,']);

		expect($adapter->validate())->toBeTrue();
		expect($adapter->tags)->toBe('environment=production, campaign = welcome-v2 ,');
	});

	it('rejects invalid tags', function (string $tags): void {
		$adapter = new Adapter(['token' => 'secret', 'tags' => $tags]);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('tags');
	})->with([
		'without a value' => ['environment'],
		'with an empty name' => ['=production'],
		'with an empty value' => ['environment='],
		'with a space' => ['environment=pro duction'],
		'with a period' => ['version=1.0'],
		'with a long name' => [str_repeat('a', 33) . '=value'],
		'with a long value' => ['name=' . str_repeat('a', 65)],
		'with a reserved name' => ['__Lettermint_tag=value'],
		'with a duplicate name' => ['site=a, site=b'],
		'with too many tags' => [implode(',', array_map(fn(int $i): string => "tag{$i}=value", range(1, 21)))],
	]);

	it('validates tags referencing an environment variable', function (): void {
		$this->env(['LETTERMINT_TAGS' => 'environment=pro duction']);

		$adapter = new Adapter(['token' => 'secret', 'tags' => TAGS_VARIABLE]);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('tags');
		expect($adapter->tags)->toBe(TAGS_VARIABLE);
	});

	it('rejects invalid tags of a site', function (): void {
		$adapter = new Adapter(['token' => 'secret', 'sites' => [
			'site-a' => ['tags' => SITE_TAGS],
			'site-b' => ['tags' => 'site'],
		]]);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getFirstError('sites'))->toStartWith('site-b: ');
	});

	it('rejects sites with too many tags together with the tags of the adapter', function (): void {
		$adapter = new Adapter(['token' => 'secret',
			'tags' => implode(',', array_map(fn(int $i): string => "tag{$i}=value", range(1, 15))),
			'sites' => [
				'site-a' => ['tags' => implode(',', array_map(fn(int $i): string => "site{$i}=value", range(1, 5)))],
				'site-b' => ['tags' => implode(',', array_map(fn(int $i): string => "site{$i}=value", range(1, 6)))],
			],
		]);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors('sites'))->toBe(['site-b: There can be at most 20 tags together with the tags above.']);
	});

	it('counts the tags of a site replacing the ones of the adapter once', function (): void {
		$adapter = new Adapter(['token' => 'secret',
			'tags' => implode(',', array_map(fn(int $i): string => "tag{$i}=value", range(1, 20))),
			'sites' => ['site-a' => ['tags' => 'tag1=site, tag2=site']],
		]);

		expect($adapter->validate())->toBeTrue();
	});

	it('counts the tags of the adapter referencing an environment variable', function (): void {
		$this->env(['LETTERMINT_TAGS' => implode(',', array_map(fn(int $i): string => "tag{$i}=value", range(1, 20)))]);

		$adapter = new Adapter(['token' => 'secret', 'tags' => TAGS_VARIABLE, 'sites' => [
			'site-a' => ['tags' => SITE_TAGS],
		]]);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors('sites'))->toHaveCount(1);
	});

	it('reports too many tags of the adapter only on the adapter', function (): void {
		$adapter = new Adapter(['token' => 'secret',
			'tags' => implode(',', array_map(fn(int $i): string => "tag{$i}=value", range(1, 21))),
			'sites' => ['site-a' => ['tags' => SITE_TAGS]],
		]);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('tags');
		expect($adapter->getErrors())->not->toHaveKey('sites');
	});

});

describe('tags', function (): void {
	it('parses the tags', function (): void {
		$adapter = new Adapter(['tags' => ' environment=production, campaign = welcome ,, invalid, environment=staging']);

		expect($adapter->getTagList())->toBe([
			['name' => 'environment', 'value' => 'staging'],
			['name' => 'campaign', 'value' => 'welcome'],
		]);
	});

	it('resolves the tags of a site', function (): void {
		$this->env(['LETTERMINT_TAGS' => SITE_TAGS]);

		$adapter = new Adapter(['sites' => ['site-a' => ['tags' => TAGS_VARIABLE]]]);

		expect($adapter->getSiteTags('site-a'))->toBe([['name' => 'site', 'value' => 'a']]);
	});

	it('has no tags for unknown sites', function (): void {
		expect((new Adapter())->getSiteTags('site-a'))->toBe([]);
	});
});

describe('team token', function (): void {
	it('has no team token in the standard edition', function (): void {
		expect((new Adapter(['teamToken' => 'team-secret']))->getTeamToken())->toBeNull();
	});

	it('resolves the team token in the pro edition', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;
		$this->env(['LETTERMINT_TEAM_TOKEN' => 'team-secret']);

		expect((new Adapter(['teamToken' => 'team-secret']))->getTeamToken())->toBe('team-secret');
		expect((new Adapter(['teamToken' => TEAM_TOKEN_VARIABLE]))->getTeamToken())->toBe('team-secret');
	});

	it('has no team token when none is set', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;

		expect((new Adapter())->getTeamToken())->toBeNull();
		expect((new Adapter(['teamToken' => MISSING_VARIABLE]))->getTeamToken())->toBeNull();
	});
});

describe('webhook secret', function (): void {
	it('has no webhook secret in the standard edition', function (): void {
		expect((new Adapter(['webhookSecret' => 'whsec_test']))->getWebhookSecret())->toBeNull();
	});

	it('resolves the webhook secret in the pro edition', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;
		$this->env(['LETTERMINT_WEBHOOK_SECRET' => ' whsec_test ']);

		expect((new Adapter(['webhookSecret' => 'whsec_test']))->getWebhookSecret())->toBe('whsec_test');
		expect((new Adapter(['webhookSecret' => WEBHOOK_SECRET_VARIABLE]))->getWebhookSecret())->toBe('whsec_test');
	});

	it('has no webhook secret when none is set', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;

		expect((new Adapter())->getWebhookSecret())->toBeNull();
		expect((new Adapter(['webhookSecret' => MISSING_VARIABLE]))->getWebhookSecret())->toBeNull();
	});
});

/**
 * The health token as an environment variable.
 */
const HEALTH_TOKEN_VARIABLE = '$LETTERMINT_HEALTH_TOKEN';

describe('health token', function (): void {
	it('has no health token in the standard edition', function (): void {
		expect((new Adapter(['healthToken' => 'health-token-0123456789']))->getHealthToken())->toBeNull();
	});

	it('resolves the health token in the pro edition', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;
		$this->env(['LETTERMINT_HEALTH_TOKEN' => ' health-token-0123456789 ']);

		expect((new Adapter(['healthToken' => 'health-token-0123456789']))->getHealthToken())->toBe('health-token-0123456789');
		expect((new Adapter(['healthToken' => HEALTH_TOKEN_VARIABLE]))->getHealthToken())->toBe('health-token-0123456789');
	});

	it('has no health token when none is set', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;

		expect((new Adapter())->getHealthToken())->toBeNull();
		expect((new Adapter(['healthToken' => MISSING_VARIABLE]))->getHealthToken())->toBeNull();
	});
});

describe('project', function (): void {
	it('has no project in the standard edition', function (): void {
		expect((new Adapter(['project' => 'project-1']))->getProjectId())->toBeNull();
	});

	it('resolves the project in the pro edition', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;
		$this->env(['LETTERMINT_PROJECT' => ' project-1 ']);

		expect((new Adapter(['project' => 'project-1']))->getProjectId())->toBe('project-1');
		expect((new Adapter(['project' => PROJECT_VARIABLE]))->getProjectId())->toBe('project-1');
		expect((new Adapter())->getProjectId())->toBeNull();
		expect((new Adapter(['project' => MISSING_VARIABLE]))->getProjectId())->toBeNull();
	});
});

describe('site routes', function (): void {
	it('resolves the route of a site', function (): void {
		$this->env(['LETTERMINT_ROUTE' => 'env-route']);

		$adapter = new Adapter(['sites' => ['site-a' => ['route' => '$LETTERMINT_ROUTE']]]);

		expect($adapter->getSiteRoute('site-a'))->toBe('env-route');
	});

	it('has no route for a site referencing a missing environment variable', function (): void {
		$adapter = new Adapter(['sites' => ['site-a' => ['route' => MISSING_VARIABLE]]]);

		expect($adapter->getSiteRoute('site-a'))->toBeNull();
	});

	it('has no route for unknown sites', function (): void {
		expect((new Adapter())->getSiteRoute('site-a'))->toBeNull();
	});
});

describe('transport', function (): void {
	it('defines a Lettermint API transport', function (): void {
		$transport = (new Adapter(['token' => 'secret']))->defineTransport();

		expect($transport)->toBeInstanceOf(LettermintApiTransport::class);
	});

	it('passes the token and the route to the transport', function (): void {
		$transport = transport(new Adapter(['token' => 'secret', 'route' => 'outgoing']));

		expect(transportToken($transport))->toBe('secret');
		expect(transportOptions($transport))->toBe(['route' => 'outgoing']);
	});

	it('leaves the route out of the transport when none is set', function (): void {
		$transport = transport(new Adapter(['token' => 'secret']));

		expect(transportOptions($transport))->toBe([]);
	});

	it('leaves the route out of the transport when it is empty', function (): void {
		$transport = transport(new Adapter(['token' => 'secret', 'route' => '']));

		expect(transportOptions($transport))->toBe([]);
	});

	it('resolves environment variables for the transport', function (): void {
		$this->env([
			'LETTERMINT_TOKEN' => 'env-secret',
			'LETTERMINT_ROUTE' => 'env-route',
		]);

		$transport = transport(new Adapter(['token' => TOKEN_VARIABLE, 'route' => '$LETTERMINT_ROUTE']));

		expect(transportToken($transport))->toBe('env-secret');
		expect(transportOptions($transport))->toBe(['route' => 'env-route']);
	});

	it('passes the tags to the transport', function (): void {
		$transport = transport(new Adapter(['token' => 'secret', 'tags' => 'environment=production']));

		expect(transportOptions($transport))->toBe(['tags' => [['name' => 'environment', 'value' => 'production']]]);
	});

	it('leaves the site routes out of the transport in the standard edition', function (): void {
		$transport = transport(new Adapter(['token' => 'secret', 'sites' => ['site-a' => ['route' => 'outgoing']]]));

		expect(transportDispatcher($transport))->toBeNull();
	});

	it('applies the site routes in the pro edition', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;

		$transport = transport(new Adapter(['token' => 'secret', 'sites' => ['site-a' => ['route' => 'outgoing']]]));

		expect(transportDispatcher($transport))->not->toBeNull();
	});

	it('leaves the site routes out of the transport without sites', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;

		$transport = transport(new Adapter(['token' => 'secret']));

		expect(transportDispatcher($transport)?->getListeners(MessageEvent::class))->toHaveCount(1);
	});

	it('sends through the HTTP proxy of Craft', function (): void {
		Craft::$app->getConfig()->getGeneral()->httpProxy = 'http://proxy.acme.test:3128';

		expect(transportClientOptions(transport(new Adapter(['token' => 'secret']))))
			->toHaveKey('proxy', 'http://proxy.acme.test:3128');
	});

	it('sends without a proxy of its own when Craft has none', function (?string $proxy): void {
		Craft::$app->getConfig()->getGeneral()->httpProxy = $proxy;

		expect(transportClientOptions(transport(new Adapter(['token' => 'secret']))))
			->toHaveKey('proxy', null);
	})->with([
		'none' => [null],
		'empty' => [''],
	]);
});

describe('token check', function (): void {
	it('pings Lettermint with the token', function (): void {
		$requests = $this->http(new Response(200, [], 'pong'));

		expect((new Adapter(['token' => 'secret']))->checkToken())->toBe(TokenStatus::Valid);
		expect($requests)->toHaveCount(1);

		$request = $requests->getArrayCopy()[0];

		expect($request->getMethod())->toBe('GET');
		expect((string) $request->getUri())->toBe('https://api.lettermint.co/v1/ping');
		expect($request->getHeaderLine('x-lettermint-token'))->toBe('secret');
	});

	it('resolves the token from an environment variable', function (): void {
		$this->env(['LETTERMINT_TOKEN' => 'secret']);
		$requests = $this->http(new Response(200, [], 'pong'));

		(new Adapter(['token' => TOKEN_VARIABLE]))->checkToken();

		expect($requests->getArrayCopy()[0]->getHeaderLine('x-lettermint-token'))->toBe('secret');
	});

	it('reports a rejected token as invalid', function (): void {
		$this->http(jsonResponse(['message' => 'Unauthenticated.'], 401));

		expect((new Adapter(['token' => 'secret']))->checkToken())->toBe(TokenStatus::Invalid);
	});

	it('reports a malformed token as invalid without a request', function (string $token): void {
		$requests = $this->http(new Response(200, [], 'pong'));

		expect((new Adapter(['token' => $token]))->checkToken())->toBe(TokenStatus::Invalid);
		expect($requests)->toHaveCount(0);
	})->with(["sec\nret", 'sec ret']);

	it('reports other responses as unknown', function (int $code): void {
		$this->http(new Response($code));

		expect((new Adapter(['token' => 'secret']))->checkToken())->toBe(TokenStatus::Unknown);
	})->with([302, 403, 429, 500, 503]);

	it('reports an unreachable Lettermint as unknown', function (): void {
		$this->http(static fn(RequestInterface $request): never => throw new ConnectException('Timeout.', $request));

		expect((new Adapter(['token' => 'secret']))->checkToken())->toBe(TokenStatus::Unknown);
	});

	it('skips the check without a token', function (): void {
		expect((new Adapter())->checkToken())->toBeNull();
		expect((new Adapter(['token' => TOKEN_VARIABLE]))->checkToken())->toBeNull();
	});
});

describe('settings', function (): void {
	beforeEach(function (): void {
		Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
	});

	it('renders fields for the token, the route and the tags', function (): void {
		$html = (new Adapter(['token' => TOKEN_VARIABLE, 'route' => 'outgoing']))->getSettingsHtml();

		expect($html)
			->toContain('id="token-field"')
			->toContain('Project API Token')
			->toContain('Lettermint project API token.')
			->toContain('id="route-field"')
			->toContain('Route Slug')
			->toContain('Lettermint project route slug.')
			->toContain('id="tags-field"')
			->toContain('Lettermint tags as comma-separated name=value pairs');
	});

	it('passes the token and the route to the inputs', function (): void {
		(new Adapter(['token' => TOKEN_VARIABLE, 'route' => 'outgoing']))->getSettingsHtml();

		// The inputs are rendered by Vue, from props registered as JS.
		$js = registeredJs();

		expect($js)
			->toContain('"initialValue":"$LETTERMINT_TOKEN","id":"token","name":"token"')
			->toContain('"initialValue":"outgoing","id":"route","name":"route"');
	});

	it('renders the validation errors', function (): void {
		$adapter = new Adapter();
		$adapter->validate();

		expect($adapter->getSettingsHtml())->toContain($adapter->getFirstError('token'));
	});

	it('does not check the tokens', function (): void {
		// Requests fail the test, as none of them is mocked.
		$html = (new Adapter(['token' => 'secret']))->getSettingsHtml();

		expect($html)
			->toContain('id="token-field"')
			->not->toContain('token-status');
	});

	it('registers the styles of the pro badge in the pro edition', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;

		(new Adapter())->getSettingsHtml();

		expect(Craft::$app->getView()->assetBundles)->toHaveKey(UtilityAsset::class);
	});

	it('registers no styles in the standard edition', function (): void {
		(new Adapter())->getSettingsHtml();

		expect(Craft::$app->getView()->assetBundles)->not->toHaveKey(UtilityAsset::class);
	});

	it('leaves out the sites in the standard edition', function (): void {
		expect((new Adapter())->getSettingsHtml())->not->toContain('id="sites-field"');
	});

	it('does not pass the sites on in the standard edition', function (): void {
		$html = (new Adapter(['sites' => ['site-a' => ['route' => 'shop-a', 'tags' => SITE_TAGS]]]))->getSettingsHtml();

		expect($html)
			->not->toContain('name="sites[site-a]')
			->not->toContain('shop-a');
	});

	it('leaves out the team token field in the standard edition', function (): void {
		expect((new Adapter())->getSettingsHtml())->not->toContain('id="teamToken-field"');
	});

	it('does not pass the team token on in the standard edition', function (): void {
		expect((new Adapter(['teamToken' => TEAM_TOKEN_VARIABLE]))->getSettingsHtml())
			->not->toContain('name="teamToken"')
			->not->toContain(TEAM_TOKEN_VARIABLE);
	});

	it('renders the team token field in the pro edition', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;

		$html = (new Adapter(['teamToken' => TEAM_TOKEN_VARIABLE]))->getSettingsHtml();
		$js = registeredJs();

		expect($html)
			->toContain('id="teamToken-field"')
			->toContain('Team API Token')
			->toContain('Lettermint team API token.')
			->not->toContain('type="hidden" name="teamToken"');
		expect($js)->toContain('"initialValue":"$LETTERMINT_TEAM_TOKEN","id":"teamToken","name":"teamToken"');
	});

	it('does not pass the project on in the standard edition', function (): void {
		expect((new Adapter(['project' => PROJECT_VARIABLE]))->getSettingsHtml())
			->not->toContain('name="project"')
			->not->toContain(PROJECT_VARIABLE)
			->not->toContain(PROJECT_FIELD);
	});

	it('renders the project field in the pro edition', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;

		$html = (new Adapter(['project' => PROJECT_VARIABLE]))->getSettingsHtml();
		$js = registeredJs();

		expect($html)
			->toContain(PROJECT_FIELD)
			->not->toContain('type="hidden" name="project"');
		expect($js)
			->toContain('"initialValue":"$LETTERMINT_PROJECT","id":"project","name":"project"')
			->not->toContain('"label":"Projects"');
	});

	it('requests no projects for the settings', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;
		$requests = $this->http(jsonResponse(['data' => [['id' => 'project-1', 'name' => 'Website']]]));

		$html = (new Adapter(['teamToken' => 'team-secret']))->getSettingsHtml();
		$js = registeredJs();

		expect($html)->toContain(PROJECT_FIELD);
		expect($js)->not->toContain('"label":"Projects"');
		expect(array_filter($requests->getArrayCopy(), static fn(RequestInterface $request): bool => str_starts_with($request->getUri()->getPath(), '/v1/projects')))->toBeEmpty();
	});

	it('does not pass the webhook secret on in the standard edition', function (): void {
		expect((new Adapter(['webhookSecret' => WEBHOOK_SECRET_VARIABLE]))->getSettingsHtml())
			->not->toContain('name="webhookSecret"')
			->not->toContain(WEBHOOK_SECRET_VARIABLE)
			->not->toContain('id="webhookSecret-field"');
	});

	it('renders the webhook secret field with the endpoint in the pro edition', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;

		$html = (new Adapter(['webhookSecret' => WEBHOOK_SECRET_VARIABLE]))->getSettingsHtml();
		$js = registeredJs();

		expect($html)
			->toContain('id="webhookSecret-field"')
			->toContain('Webhook Secret')
			->toContain(htmlspecialchars(Webhooks::url()))
			->not->toContain('type="hidden" name="webhookSecret"');
		expect($js)->toContain('"initialValue":"$LETTERMINT_WEBHOOK_SECRET","id":"webhookSecret","name":"webhookSecret"');
	});

	it('does not pass the health token on in the standard edition', function (): void {
		expect((new Adapter(['healthToken' => HEALTH_TOKEN_VARIABLE]))->getSettingsHtml())
			->not->toContain('name="healthToken"')
			->not->toContain(HEALTH_TOKEN_VARIABLE)
			->not->toContain('id="healthToken-field"');
	});

	it('renders the health token field with the endpoint in the pro edition', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;

		$html = (new Adapter(['healthToken' => HEALTH_TOKEN_VARIABLE]))->getSettingsHtml();

		expect($html)
			->toContain('id="healthToken-field"')
			->toContain('Health Token')
			->toContain(htmlspecialchars(Health::url()))
			->toContain('At least 16 characters')
			->not->toContain('type="hidden" name="healthToken"');
	});

	it('renders the sites in the pro edition', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;

		expect((new Adapter())->getSettingsHtml())
			->toContain('id="sites-field"')
			->toContain('<span class="lettermint-pro-badge">Pro</span>')
			->toContain('Route slug and tags for emails of each site.')
			->toContain('This can begin with an environment variable.')
			->toContain('href="https://craftcms.com/docs/5.x/configure.html#control-panel-settings"');
	});
});

describe('model', function (): void {
	it('fires the init event of models', function (): void {
		$fired = false;

		Event::on(Adapter::class, Model::EVENT_INIT, static function () use (&$fired): void {
			$fired = true;
		});

		expect(new Adapter())->toBeInstanceOf(Adapter::class);
		expect($fired)->toBeTrue();
	});

	it('normalizes the sites that are set once it is created', function (): void {
		$adapter = new Adapter(['token' => 'secret']);
		$adapter->sites = ['site-a' => ['route' => ' shop-a ', 'tags' => ''], 'site-b' => ['route' => '']];

		expect($adapter->validate())->toBeTrue();
		expect($adapter->sites)->toBe(['site-a' => ['route' => 'shop-a']]);
	});

	it('takes a health token of the minimum length', function (): void {
		$adapter = new Adapter(['token' => 'secret', 'healthToken' => str_repeat('a', Adapter::HEALTH_TOKEN_MIN_LENGTH)]);

		expect($adapter->validate())->toBeTrue();
	});
});

describe('cache key', function (): void {
	it('names the data that it is for', function (): void {
		expect((new Adapter(['token' => 'secret']))->cacheKey('health'))->toMatch('/^lettermint\.health\.[0-9a-f]{64}$/');
	});

	it('changes with the system email address', function (): void {
		$adapter = new Adapter(['token' => 'secret']);
		$key = $adapter->cacheKey('health');

		Craft::$app->getProjectConfig()->set('email', ['fromEmail' => 'other@acme.test', 'transportType' => Adapter::class]);

		expect($adapter->cacheKey('health'))->not->toBe($key);
	});

	it('has a key without the plugin', function (): void {
		Plugin::setInstance(null);

		expect((new Adapter(['token' => 'secret']))->cacheKey('health'))->toStartWith('lettermint.health.');
	});
});

describe('sites', function (): void {
	it('renders a row for each site, also the disabled ones', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;
		Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
		addSite('site-a', 'shop', 'Shop');
		addSite('site-b', 'archive', 'Archive', enabled: false);

		$rows = null;
		Event::on(View::class, View::EVENT_BEFORE_RENDER_TEMPLATE, static function (TemplateEvent $event) use (&$rows): void {
			$rows ??= $event->variables['siteRows'] ?? null;
		});

		(new Adapter(['sites' => ['site-b' => ['route' => 'archive-route', 'tags' => 'site=archive']]]))->getSettingsHtml();

		expect($rows)->toBe([
			'site-a' => ['heading' => 'Shop', 'route' => '', 'tags' => ''],
			'site-b' => ['heading' => 'Archive', 'route' => 'archive-route', 'tags' => 'site=archive'],
		]);
	});

	it('renders no row for a site that is not saved', function (): void {
		plugin()->edition = Plugin::EDITION_PRO;
		Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
		// The site that is not saved comes first, so the sites after it are still rendered.
		addSite('site-b', 'archive', 'Archive')->uid = null;
		addSite('site-a', 'shop', 'Shop');

		$rows = null;
		Event::on(View::class, View::EVENT_BEFORE_RENDER_TEMPLATE, static function (TemplateEvent $event) use (&$rows): void {
			$rows ??= $event->variables['siteRows'] ?? null;
		});

		(new Adapter())->getSettingsHtml();

		expect($rows)->toBe(['site-a' => ['heading' => 'Shop', 'route' => '', 'tags' => '']]);
	});

	it('checks no empty token', function (): void {
		$requests = $this->http(jsonResponse([]));

		// Craft takes an empty setting as null, which the token can still be set to afterwards.
		$adapter = new Adapter();
		$adapter->token = '';

		expect($adapter->checkToken())->toBeNull();
		expect($requests)->toHaveCount(0);
	});

	it('defines no transport without a token', function (): void {
		expect(fn(): LettermintApiTransport => transport(new Adapter()))
			->toThrow(InvalidArgumentException::class, 'A valid project token is required.');
	});
});
