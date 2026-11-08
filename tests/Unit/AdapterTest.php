<?php

declare(strict_types=1);

use craft\base\Model;
use craft\events\TemplateEvent;
use craft\web\View;
use Lettermint\SymfonyMailer\Transport\LettermintApiTransport;
use Lettermint\SymfonyMailer\Transport\PayloadBuilder;
use Psr\Http\Message\RequestInterface;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\helpers\Urls;
use SomehowDigital\Craft\Lettermint\validators\HealthTokenValidator;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;
use Symfony\Component\EventDispatcher\EventDispatcher;
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
const PROJECT_FIELD = 'id="projectId-field"';

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

it('is named Lettermint', function (): void {
	expect(Adapter::displayName())->toBe('Lettermint');
});

it('labels its attributes', function (): void {
	expect((new Adapter())->attributeLabels())->toBe([
		'token' => 'Project API Token',
		'teamToken' => 'Team API Token',
		'projectId' => 'Project',
		'webhookSecret' => 'Webhook Secret',
		'healthToken' => 'Health Token',
		'route' => 'Route Slug',
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

		expect($adapter->resolve()->tags->toArray())->toBe([
			['name' => 'environment', 'value' => 'staging'],
			['name' => 'campaign', 'value' => 'welcome'],
		]);
	});

	it('resolves the tags of a site', function (): void {
		plugin()->edition = Edition::Pro->value;
		$this->env(['LETTERMINT_TAGS' => SITE_TAGS]);

		$adapter = new Adapter(['sites' => ['site-a' => ['tags' => TAGS_VARIABLE]]]);

		expect($adapter->resolve()->site('site-a')->tags->toArray())->toBe([['name' => 'site', 'value' => 'a']]);
	});

	it('has no tags for unknown sites', function (): void {
		expect((new Adapter())->resolve()->site('site-a')->tags->isEmpty())->toBeTrue();
	});
});

describe('team token', function (): void {
	it('has no team token in the standard edition', function (): void {
		expect((new Adapter(['teamToken' => 'team-secret']))->resolve()->teamToken)->toBeNull();
	});

	it('resolves the team token in the pro edition', function (): void {
		plugin()->edition = Edition::Pro->value;
		$this->env(['LETTERMINT_TEAM_TOKEN' => 'team-secret']);

		expect((new Adapter(['teamToken' => 'team-secret']))->resolve()->teamToken)->toBe('team-secret');
		expect((new Adapter(['teamToken' => TEAM_TOKEN_VARIABLE]))->resolve()->teamToken)->toBe('team-secret');
	});

	it('has no team token when none is set', function (): void {
		plugin()->edition = Edition::Pro->value;

		expect((new Adapter())->resolve()->teamToken)->toBeNull();
		expect((new Adapter(['teamToken' => MISSING_VARIABLE]))->resolve()->teamToken)->toBeNull();
	});
});

describe('webhook secret', function (): void {
	it('has no webhook secret in the standard edition', function (): void {
		expect((new Adapter(['webhookSecret' => 'whsec_test']))->resolve()->webhookSecret)->toBeNull();
	});

	it('resolves the webhook secret in the pro edition', function (): void {
		plugin()->edition = Edition::Pro->value;
		$this->env(['LETTERMINT_WEBHOOK_SECRET' => ' whsec_test ']);

		expect((new Adapter(['webhookSecret' => 'whsec_test']))->resolve()->webhookSecret)->toBe('whsec_test');
		expect((new Adapter(['webhookSecret' => WEBHOOK_SECRET_VARIABLE]))->resolve()->webhookSecret)->toBe('whsec_test');
	});

	it('has no webhook secret when none is set', function (): void {
		plugin()->edition = Edition::Pro->value;

		expect((new Adapter())->resolve()->webhookSecret)->toBeNull();
		expect((new Adapter(['webhookSecret' => MISSING_VARIABLE]))->resolve()->webhookSecret)->toBeNull();
	});
});

/**
 * The health token as an environment variable.
 */
const HEALTH_TOKEN_VARIABLE = '$LETTERMINT_HEALTH_TOKEN';

describe('health token', function (): void {
	it('has no health token in the standard edition', function (): void {
		expect((new Adapter(['healthToken' => 'health-token-0123456789']))->resolve()->healthToken)->toBeNull();
	});

	it('resolves the health token in the pro edition', function (): void {
		plugin()->edition = Edition::Pro->value;
		$this->env(['LETTERMINT_HEALTH_TOKEN' => ' health-token-0123456789 ']);

		expect((new Adapter(['healthToken' => 'health-token-0123456789']))->resolve()->healthToken)->toBe('health-token-0123456789');
		expect((new Adapter(['healthToken' => HEALTH_TOKEN_VARIABLE]))->resolve()->healthToken)->toBe('health-token-0123456789');
	});

	it('has no health token when none is set', function (): void {
		plugin()->edition = Edition::Pro->value;

		expect((new Adapter())->resolve()->healthToken)->toBeNull();
		expect((new Adapter(['healthToken' => MISSING_VARIABLE]))->resolve()->healthToken)->toBeNull();
	});
});

describe('project', function (): void {
	it('has no project in the standard edition', function (): void {
		expect((new Adapter(['projectId' => 'project-1']))->resolve()->projectId)->toBeNull();
	});

	it('resolves the project in the pro edition', function (): void {
		plugin()->edition = Edition::Pro->value;
		$this->env(['LETTERMINT_PROJECT' => ' project-1 ']);

		expect((new Adapter(['projectId' => 'project-1']))->resolve()->projectId)->toBe('project-1');
		expect((new Adapter(['projectId' => PROJECT_VARIABLE]))->resolve()->projectId)->toBe('project-1');
		expect((new Adapter())->resolve()->projectId)->toBeNull();
		expect((new Adapter(['projectId' => MISSING_VARIABLE]))->resolve()->projectId)->toBeNull();
	});
});

describe('site routes', function (): void {
	it('resolves the route of a site', function (): void {
		plugin()->edition = Edition::Pro->value;
		$this->env(['LETTERMINT_ROUTE' => 'env-route']);

		$adapter = new Adapter(['sites' => ['site-a' => ['route' => '$LETTERMINT_ROUTE']]]);

		expect($adapter->resolve()->site('site-a')->route)->toBe('env-route');
	});

	it('has no route for a site referencing a missing environment variable', function (): void {
		plugin()->edition = Edition::Pro->value;
		$adapter = new Adapter(['sites' => ['site-a' => ['route' => MISSING_VARIABLE]]]);

		expect($adapter->resolve()->site('site-a')->route)->toBeNull();
	});

	it('has no route for unknown sites', function (): void {
		plugin()->edition = Edition::Pro->value;

		expect((new Adapter())->resolve()->site('site-a')->route)->toBeNull();
	});

	it('has no routes or tags of sites in the standard edition', function (): void {
		$settings = (new Adapter(['sites' => ['site-a' => ['route' => 'shop', 'tags' => SITE_TAGS]]]))->resolve();

		expect($settings->sites)->toBe([]);
		expect($settings->site('site-a')->route)->toBeNull();
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
		plugin()->edition = Edition::Pro->value;

		$transport = transport(new Adapter(['token' => 'secret', 'sites' => ['site-a' => ['route' => 'outgoing']]]));

		expect(transportDispatcher($transport))->not->toBeNull();
	});

	it('leaves the site routes out of the transport without sites', function (): void {
		plugin()->edition = Edition::Pro->value;

		$transport = transport(new Adapter(['token' => 'secret']));

		expect(transportDispatcher($transport))->toBeNull();
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
		plugin()->edition = Edition::Pro->value;

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
		plugin()->edition = Edition::Pro->value;

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
		expect((new Adapter(['projectId' => PROJECT_VARIABLE]))->getSettingsHtml())
			->not->toContain('name="projectId"')
			->not->toContain(PROJECT_VARIABLE)
			->not->toContain(PROJECT_FIELD);
	});

	it('renders the project field in the pro edition', function (): void {
		plugin()->edition = Edition::Pro->value;

		$html = (new Adapter(['projectId' => PROJECT_VARIABLE]))->getSettingsHtml();
		$js = registeredJs();

		expect($html)
			->toContain(PROJECT_FIELD)
			->not->toContain('type="hidden" name="projectId"');
		expect($js)
			->toContain('"initialValue":"$LETTERMINT_PROJECT","id":"projectId","name":"projectId"')
			->not->toContain('"label":"Projects"');
	});

	it('requests no projects for the settings', function (): void {
		plugin()->edition = Edition::Pro->value;
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
		plugin()->edition = Edition::Pro->value;

		$html = (new Adapter(['webhookSecret' => WEBHOOK_SECRET_VARIABLE]))->getSettingsHtml();
		$js = registeredJs();

		expect($html)
			->toContain('id="webhookSecret-field"')
			->toContain('Webhook Secret')
			->toContain(htmlspecialchars(Urls::webhook()))
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
		plugin()->edition = Edition::Pro->value;

		$html = (new Adapter(['healthToken' => HEALTH_TOKEN_VARIABLE]))->getSettingsHtml();

		expect($html)
			->toContain('id="healthToken-field"')
			->toContain('Health Token')
			->toContain(htmlspecialchars(Urls::health()))
			->toContain('At least 16 characters')
			->not->toContain('type="hidden" name="healthToken"');
	});

	it('renders the sites in the pro edition', function (): void {
		plugin()->edition = Edition::Pro->value;

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
		$adapter = new Adapter(['token' => 'secret', 'healthToken' => str_repeat('a', HealthTokenValidator::MIN_LENGTH)]);

		expect($adapter->validate())->toBeTrue();
	});
});

describe('sites', function (): void {
	it('renders a row for each site, also the disabled ones', function (): void {
		plugin()->edition = Edition::Pro->value;
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
		plugin()->edition = Edition::Pro->value;
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

	it('has no empty token', function (): void {
		// Craft takes an empty setting as null, which the token can still be set to afterwards.
		$adapter = new Adapter();
		$adapter->token = '';

		expect($adapter->resolve()->token)->toBeNull();
	});

	it('resolves the token untrimmed', function (): void {
		$this->env(['LETTERMINT_TOKEN' => ' secret ']);

		expect((new Adapter(['token' => TOKEN_VARIABLE]))->resolve()->token)->toBe(' secret ');
		expect((new Adapter(['token' => MISSING_VARIABLE]))->resolve()->token)->toBeNull();
	});

	it('resolves the system email address trimmed', function (): void {
		$this->env(['LETTERMINT_SENDER' => ' hello@acme.test ']);

		lettermintEmail(['token' => 'secret'], '$LETTERMINT_SENDER');
		expect((new Adapter())->resolve()->senderEmail)->toBe('hello@acme.test');

		lettermintEmail(['token' => 'secret'], '$LETTERMINT_MISSING_SENDER');
		expect((new Adapter())->resolve()->senderEmail)->toBeNull();
	});

	it('defines no transport without a token', function (): void {
		expect(fn(): LettermintApiTransport => transport(new Adapter()))
			->toThrow(InvalidArgumentException::class, 'A valid project token is required.');
	});
});
