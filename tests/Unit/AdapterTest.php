<?php

use craft\web\View;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Lettermint\SymfonyMailer\Transport\LettermintApiTransport;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Health;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\TokenStatus;
use SomehowDigital\Craft\Lettermint\Webhooks;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * The value of a private property of an object.
 */
function privateProperty(object $object, string $property): mixed
{
	return (new ReflectionProperty($object, $property))->getValue($object);
}

/**
 * The project token of the transport.
 */
function transportToken(LettermintApiTransport $transport): string
{
	return privateProperty($transport, 'projectToken');
}

/**
 * The default message options of the transport.
 */
function transportOptions(LettermintApiTransport $transport): array
{
	return privateProperty(privateProperty($transport, 'payloadBuilder'), 'defaults');
}

/**
 * The event dispatcher of the transport, which applies the site routes.
 */
function transportDispatcher(LettermintApiTransport $transport): mixed
{
	return (new ReflectionProperty(AbstractTransport::class, 'dispatcher'))->getValue($transport);
}

it('is named Lettermint', function () {
	expect(Adapter::displayName())->toBe('Lettermint');
});

it('labels its attributes', function () {
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

it('has no token or route by default', function () {
	$adapter = new Adapter();

	expect($adapter->token)->toBeNull();
	expect($adapter->teamToken)->toBeNull();
	expect($adapter->route)->toBeNull();
	expect($adapter->tags)->toBeNull();
	expect($adapter->sites)->toBe([]);
});

describe('validation', function () {
	it('requires a token', function () {
		$adapter = new Adapter();

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('token');
		expect($adapter->getErrors())->not->toHaveKey('route');
	});

	it('passes with a token and without a route', function () {
		$adapter = new Adapter(['token' => 'secret']);

		expect($adapter->validate())->toBeTrue();
	});

	it('passes with a token and a route', function () {
		$adapter = new Adapter(['token' => 'secret', 'route' => 'outgoing']);

		expect($adapter->validate())->toBeTrue();
	});

	it('trims the token and the route', function () {
		$adapter = new Adapter(['token' => '  secret ', 'route' => ' outgoing  ']);

		expect($adapter->validate())->toBeTrue();
		expect($adapter->token)->toBe('secret');
		expect($adapter->route)->toBe('outgoing');
	});

	it('rejects a token that is only whitespace', function () {
		$adapter = new Adapter(['token' => '   ']);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('token');
	});

	it('validates a token referencing a set environment variable', function () {
		$this->env(['LETTERMINT_TOKEN' => 'secret']);

		$adapter = new Adapter(['token' => '$LETTERMINT_TOKEN']);

		expect($adapter->validate())->toBeTrue();
		expect($adapter->token)->toBe('$LETTERMINT_TOKEN');
	});

	it('rejects a token referencing a missing environment variable', function () {
		$adapter = new Adapter(['token' => '$LETTERMINT_MISSING']);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('token');
		expect($adapter->token)->toBe('$LETTERMINT_MISSING');
	});

	it('trims the team token', function () {
		$adapter = new Adapter(['token' => 'secret', 'teamToken' => ' team-secret  ']);

		expect($adapter->validate())->toBeTrue();
		expect($adapter->teamToken)->toBe('team-secret');
	});

	it('trims the sites and drops the ones without a route', function () {
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

	it('keeps the sites with tags', function () {
		$adapter = new Adapter(['token' => 'secret', 'sites' => [
			'site-a' => ['route' => '', 'tags' => ' site=a '],
			'site-b' => ['route' => 'shop-b', 'tags' => 'site=b'],
			'site-c' => ['route' => '', 'tags' => ' '],
		]]);

		expect($adapter->validate())->toBeTrue();
		expect($adapter->sites)->toBe([
			'site-a' => ['tags' => 'site=a'],
			'site-b' => ['route' => 'shop-b', 'tags' => 'site=b'],
		]);
	});

	it('passes with valid tags', function () {
		$adapter = new Adapter(['token' => 'secret', 'tags' => ' environment=production, campaign = welcome-v2 ,']);

		expect($adapter->validate())->toBeTrue();
		expect($adapter->tags)->toBe('environment=production, campaign = welcome-v2 ,');
	});

	it('rejects invalid tags', function (string $tags) {
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
		'with too many tags' => [implode(',', array_map(fn ($i) => "tag{$i}=value", range(1, 21)))],
	]);

	it('validates tags referencing an environment variable', function () {
		$this->env(['LETTERMINT_TAGS' => 'environment=pro duction']);

		$adapter = new Adapter(['token' => 'secret', 'tags' => '$LETTERMINT_TAGS']);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('tags');
		expect($adapter->tags)->toBe('$LETTERMINT_TAGS');
	});

	it('rejects invalid tags of a site', function () {
		$adapter = new Adapter(['token' => 'secret', 'sites' => [
			'site-a' => ['tags' => 'site=a'],
			'site-b' => ['tags' => 'site'],
		]]);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getFirstError('sites'))->toStartWith('site-b: ');
	});

	it('rejects sites with too many tags together with the tags of the adapter', function () {
		$adapter = new Adapter(['token' => 'secret',
			'tags' => implode(',', array_map(fn ($i) => "tag{$i}=value", range(1, 15))),
			'sites' => [
				'site-a' => ['tags' => implode(',', array_map(fn ($i) => "site{$i}=value", range(1, 5)))],
				'site-b' => ['tags' => implode(',', array_map(fn ($i) => "site{$i}=value", range(1, 6)))],
			],
		]);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors('sites'))->toBe(['site-b: There can be at most 20 tags together with the tags above.']);
	});

	it('counts the tags of a site replacing the ones of the adapter once', function () {
		$adapter = new Adapter(['token' => 'secret',
			'tags' => implode(',', array_map(fn ($i) => "tag{$i}=value", range(1, 20))),
			'sites' => ['site-a' => ['tags' => 'tag1=site, tag2=site']],
		]);

		expect($adapter->validate())->toBeTrue();
	});

	it('counts the tags of the adapter referencing an environment variable', function () {
		$this->env(['LETTERMINT_TAGS' => implode(',', array_map(fn ($i) => "tag{$i}=value", range(1, 20)))]);

		$adapter = new Adapter(['token' => 'secret', 'tags' => '$LETTERMINT_TAGS', 'sites' => [
			'site-a' => ['tags' => 'site=a'],
		]]);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors('sites'))->toHaveCount(1);
	});

	it('reports too many tags of the adapter only on the adapter', function () {
		$adapter = new Adapter(['token' => 'secret',
			'tags' => implode(',', array_map(fn ($i) => "tag{$i}=value", range(1, 21))),
			'sites' => ['site-a' => ['tags' => 'site=a']],
		]);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('tags');
		expect($adapter->getErrors())->not->toHaveKey('sites');
	});
});

describe('tags', function () {
	it('parses the tags', function () {
		$adapter = new Adapter(['tags' => ' environment=production, campaign = welcome ,, invalid, environment=staging']);

		expect($adapter->getTagList())->toBe([
			['name' => 'environment', 'value' => 'staging'],
			['name' => 'campaign', 'value' => 'welcome'],
		]);
	});

	it('resolves the tags of a site', function () {
		$this->env(['LETTERMINT_TAGS' => 'site=a']);

		$adapter = new Adapter(['sites' => ['site-a' => ['tags' => '$LETTERMINT_TAGS']]]);

		expect($adapter->getSiteTags('site-a'))->toBe([['name' => 'site', 'value' => 'a']]);
	});

	it('has no tags for unknown sites', function () {
		expect((new Adapter())->getSiteTags('site-a'))->toBe([]);
	});
});

describe('team token', function () {
	it('has no team token in the standard edition', function () {
		expect((new Adapter(['teamToken' => 'team-secret']))->getTeamToken())->toBeNull();
	});

	it('resolves the team token in the pro edition', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;
		$this->env(['LETTERMINT_TEAM_TOKEN' => 'team-secret']);

		expect((new Adapter(['teamToken' => 'team-secret']))->getTeamToken())->toBe('team-secret');
		expect((new Adapter(['teamToken' => '$LETTERMINT_TEAM_TOKEN']))->getTeamToken())->toBe('team-secret');
	});

	it('has no team token when none is set', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;

		expect((new Adapter())->getTeamToken())->toBeNull();
		expect((new Adapter(['teamToken' => '$LETTERMINT_MISSING']))->getTeamToken())->toBeNull();
	});
});

describe('webhook secret', function () {
	it('has no webhook secret in the standard edition', function () {
		expect((new Adapter(['webhookSecret' => 'whsec_test']))->getWebhookSecret())->toBeNull();
	});

	it('resolves the webhook secret in the pro edition', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;
		$this->env(['LETTERMINT_WEBHOOK_SECRET' => ' whsec_test ']);

		expect((new Adapter(['webhookSecret' => 'whsec_test']))->getWebhookSecret())->toBe('whsec_test');
		expect((new Adapter(['webhookSecret' => '$LETTERMINT_WEBHOOK_SECRET']))->getWebhookSecret())->toBe('whsec_test');
	});

	it('has no webhook secret when none is set', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;

		expect((new Adapter())->getWebhookSecret())->toBeNull();
		expect((new Adapter(['webhookSecret' => '$LETTERMINT_MISSING']))->getWebhookSecret())->toBeNull();
	});
});

/**
 * The health token as an environment variable.
 */
const HEALTH_TOKEN_VARIABLE = '$LETTERMINT_HEALTH_TOKEN';

describe('health token', function () {
	it('has no health token in the standard edition', function () {
		expect((new Adapter(['healthToken' => 'health-token-0123456789']))->getHealthToken())->toBeNull();
	});

	it('resolves the health token in the pro edition', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;
		$this->env(['LETTERMINT_HEALTH_TOKEN' => ' health-token-0123456789 ']);

		expect((new Adapter(['healthToken' => 'health-token-0123456789']))->getHealthToken())->toBe('health-token-0123456789');
		expect((new Adapter(['healthToken' => HEALTH_TOKEN_VARIABLE]))->getHealthToken())->toBe('health-token-0123456789');
	});

	it('has no health token when none is set', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;

		expect((new Adapter())->getHealthToken())->toBeNull();
		expect((new Adapter(['healthToken' => '$LETTERMINT_MISSING']))->getHealthToken())->toBeNull();
	});
});

describe('project', function () {
	it('has no project in the standard edition', function () {
		expect((new Adapter(['project' => 'project-1']))->getProjectId())->toBeNull();
	});

	it('resolves the project in the pro edition', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;
		$this->env(['LETTERMINT_PROJECT' => ' project-1 ']);

		expect((new Adapter(['project' => 'project-1']))->getProjectId())->toBe('project-1');
		expect((new Adapter(['project' => '$LETTERMINT_PROJECT']))->getProjectId())->toBe('project-1');
		expect((new Adapter())->getProjectId())->toBeNull();
		expect((new Adapter(['project' => '$LETTERMINT_MISSING']))->getProjectId())->toBeNull();
	});
});

describe('site routes', function () {
	it('resolves the route of a site', function () {
		$this->env(['LETTERMINT_ROUTE' => 'env-route']);

		$adapter = new Adapter(['sites' => ['site-a' => ['route' => '$LETTERMINT_ROUTE']]]);

		expect($adapter->getSiteRoute('site-a'))->toBe('env-route');
	});

	it('has no route for a site referencing a missing environment variable', function () {
		$adapter = new Adapter(['sites' => ['site-a' => ['route' => '$LETTERMINT_MISSING']]]);

		expect($adapter->getSiteRoute('site-a'))->toBeNull();
	});

	it('has no route for unknown sites', function () {
		expect((new Adapter())->getSiteRoute('site-a'))->toBeNull();
	});
});

describe('transport', function () {
	it('defines a Lettermint API transport', function () {
		$transport = (new Adapter(['token' => 'secret']))->defineTransport();

		expect($transport)->toBeInstanceOf(LettermintApiTransport::class);
	});

	it('passes the token and the route to the transport', function () {
		$transport = (new Adapter(['token' => 'secret', 'route' => 'outgoing']))->defineTransport();

		expect(transportToken($transport))->toBe('secret');
		expect(transportOptions($transport))->toBe(['route' => 'outgoing']);
	});

	it('leaves the route out of the transport when none is set', function () {
		$transport = (new Adapter(['token' => 'secret']))->defineTransport();

		expect(transportOptions($transport))->toBe([]);
	});

	it('leaves the route out of the transport when it is empty', function () {
		$transport = (new Adapter(['token' => 'secret', 'route' => '']))->defineTransport();

		expect(transportOptions($transport))->toBe([]);
	});

	it('resolves environment variables for the transport', function () {
		$this->env([
			'LETTERMINT_TOKEN' => 'env-secret',
			'LETTERMINT_ROUTE' => 'env-route',
		]);

		$transport = (new Adapter(['token' => '$LETTERMINT_TOKEN', 'route' => '$LETTERMINT_ROUTE']))->defineTransport();

		expect(transportToken($transport))->toBe('env-secret');
		expect(transportOptions($transport))->toBe(['route' => 'env-route']);
	});

	it('passes the tags to the transport', function () {
		$transport = (new Adapter(['token' => 'secret', 'tags' => 'environment=production']))->defineTransport();

		expect(transportOptions($transport))->toBe(['tags' => [['name' => 'environment', 'value' => 'production']]]);
	});

	it('leaves the site routes out of the transport in the standard edition', function () {
		$transport = (new Adapter(['token' => 'secret', 'sites' => ['site-a' => ['route' => 'outgoing']]]))->defineTransport();

		expect(transportDispatcher($transport))->toBeNull();
	});

	it('applies the site routes in the pro edition', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;

		$transport = (new Adapter(['token' => 'secret', 'sites' => ['site-a' => ['route' => 'outgoing']]]))->defineTransport();

		expect(transportDispatcher($transport))->not->toBeNull();
	});

	it('leaves the site routes out of the transport without sites', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;

		$transport = (new Adapter(['token' => 'secret']))->defineTransport();

		expect(transportDispatcher($transport))->toBeNull();
	});
});

describe('token check', function () {
	it('pings Lettermint with the token', function () {
		$requests = $this->http(new Response(200, [], 'pong'));

		expect((new Adapter(['token' => 'secret']))->checkToken())->toBe(TokenStatus::Valid);
		expect($requests)->toHaveCount(1);
		expect($requests[0]->getMethod())->toBe('GET');
		expect((string) $requests[0]->getUri())->toBe('https://api.lettermint.co/v1/ping');
		expect($requests[0]->getHeaderLine('x-lettermint-token'))->toBe('secret');
	});

	it('resolves the token from an environment variable', function () {
		$this->env(['LETTERMINT_TOKEN' => 'secret']);
		$requests = $this->http(new Response(200, [], 'pong'));

		(new Adapter(['token' => '$LETTERMINT_TOKEN']))->checkToken();

		expect($requests[0]->getHeaderLine('x-lettermint-token'))->toBe('secret');
	});

	it('reports a rejected token as invalid', function () {
		$this->http(jsonResponse(['message' => 'Unauthenticated.'], 401));

		expect((new Adapter(['token' => 'secret']))->checkToken())->toBe(TokenStatus::Invalid);
	});

	it('reports a malformed token as invalid without a request', function (string $token) {
		$requests = $this->http(new Response(200, [], 'pong'));

		expect((new Adapter(['token' => $token]))->checkToken())->toBe(TokenStatus::Invalid);
		expect($requests)->toHaveCount(0);
	})->with(["sec\nret", 'sec ret']);

	it('reports other responses as unknown', function (int $code) {
		$this->http(new Response($code));

		expect((new Adapter(['token' => 'secret']))->checkToken())->toBe(TokenStatus::Unknown);
	})->with([302, 403, 429, 500, 503]);

	it('reports an unreachable Lettermint as unknown', function () {
		$this->http(static fn (RequestInterface $request) => throw new ConnectException('Timeout.', $request));

		expect((new Adapter(['token' => 'secret']))->checkToken())->toBe(TokenStatus::Unknown);
	});

	it('skips the check without a token', function () {
		expect((new Adapter())->checkToken())->toBeNull();
		expect((new Adapter(['token' => '$LETTERMINT_TOKEN']))->checkToken())->toBeNull();
	});
});

describe('settings', function () {
	beforeEach(function () {
		Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
	});

	it('renders fields for the token, the route and the tags', function () {
		$html = (new Adapter(['token' => '$LETTERMINT_TOKEN', 'route' => 'outgoing']))->getSettingsHtml();

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

	it('passes the token and the route to the inputs', function () {
		(new Adapter(['token' => '$LETTERMINT_TOKEN', 'route' => 'outgoing']))->getSettingsHtml();

		// The inputs are rendered by Vue, from props registered as JS.
		$js = implode("\n", array_merge(...array_values(Craft::$app->getView()->js)));

		expect($js)
			->toContain('"initialValue":"$LETTERMINT_TOKEN","id":"token","name":"token"')
			->toContain('"initialValue":"outgoing","id":"route","name":"route"');
	});

	it('renders the validation errors', function () {
		$adapter = new Adapter();
		$adapter->validate();

		expect($adapter->getSettingsHtml())->toContain($adapter->getFirstError('token'));
	});

	it('does not check the tokens', function () {
		// Requests fail the test, as none of them is mocked.
		$html = (new Adapter(['token' => 'secret']))->getSettingsHtml();

		expect($html)
			->toContain('id="token-field"')
			->not->toContain('token-status');
	});

	it('leaves out the sites in the standard edition', function () {
		expect((new Adapter())->getSettingsHtml())->not->toContain('id="sites-field"');
	});

	it('keeps the sites in the standard edition', function () {
		$html = (new Adapter(['sites' => ['site-a' => ['route' => 'shop-a', 'tags' => 'site=a']]]))->getSettingsHtml();

		expect($html)
			->toContain('name="sites[site-a][route]" value="shop-a"')
			->toContain('name="sites[site-a][tags]" value="site=a"');
	});

	it('leaves out the team token field in the standard edition', function () {
		expect((new Adapter())->getSettingsHtml())->not->toContain('id="teamToken-field"');
	});

	it('keeps the team token in the standard edition', function () {
		expect((new Adapter(['teamToken' => '$LETTERMINT_TEAM_TOKEN']))->getSettingsHtml())
			->toContain('name="teamToken" value="$LETTERMINT_TEAM_TOKEN"');
	});

	it('renders the team token field in the pro edition', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;

		$html = (new Adapter(['teamToken' => '$LETTERMINT_TEAM_TOKEN']))->getSettingsHtml();
		$js = implode("\n", array_merge(...array_values(Craft::$app->getView()->js)));

		expect($html)
			->toContain('id="teamToken-field"')
			->toContain('Team API Token')
			->toContain('Lettermint team API token.')
			->not->toContain('type="hidden" name="teamToken"');
		expect($js)->toContain('"initialValue":"$LETTERMINT_TEAM_TOKEN","id":"teamToken","name":"teamToken"');
	});

	it('keeps the project in the standard edition', function () {
		expect((new Adapter(['project' => '$LETTERMINT_PROJECT']))->getSettingsHtml())
			->toContain('name="project" value="$LETTERMINT_PROJECT"')
			->not->toContain('id="project-field"');
	});

	it('renders the project field in the pro edition', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;

		$html = (new Adapter(['project' => '$LETTERMINT_PROJECT']))->getSettingsHtml();
		$js = implode("\n", array_merge(...array_values(Craft::$app->getView()->js)));

		expect($html)
			->toContain('id="project-field"')
			->not->toContain('type="hidden" name="project"');
		expect($js)
			->toContain('"initialValue":"$LETTERMINT_PROJECT","id":"project","name":"project"')
			->not->toContain('"label":"Projects"');
	});

	it('requests no projects for the settings', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;
		$requests = $this->http(jsonResponse(['data' => [['id' => 'project-1', 'name' => 'Website']]]));

		$html = (new Adapter(['teamToken' => 'team-secret']))->getSettingsHtml();
		$js = implode("
", array_merge(...array_values(Craft::$app->getView()->js)));

		expect($html)->toContain('id="project-field"');
		expect($js)->not->toContain('"label":"Projects"');
		expect(array_filter($requests->getArrayCopy(), static fn ($request) => str_starts_with($request->getUri()->getPath(), '/v1/projects')))->toBeEmpty();
	});

	it('keeps the webhook secret in the standard edition', function () {
		expect((new Adapter(['webhookSecret' => '$LETTERMINT_WEBHOOK_SECRET']))->getSettingsHtml())
			->toContain('name="webhookSecret" value="$LETTERMINT_WEBHOOK_SECRET"')
			->not->toContain('id="webhookSecret-field"');
	});

	it('renders the webhook secret field with the endpoint in the pro edition', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;

		$html = (new Adapter(['webhookSecret' => '$LETTERMINT_WEBHOOK_SECRET']))->getSettingsHtml();
		$js = implode("\n", array_merge(...array_values(Craft::$app->getView()->js)));

		expect($html)
			->toContain('id="webhookSecret-field"')
			->toContain('Webhook Secret')
			->toContain(htmlspecialchars(Webhooks::url()))
			->not->toContain('type="hidden" name="webhookSecret"');
		expect($js)->toContain('"initialValue":"$LETTERMINT_WEBHOOK_SECRET","id":"webhookSecret","name":"webhookSecret"');
	});

	it('keeps the health token in the standard edition', function () {
		expect((new Adapter(['healthToken' => HEALTH_TOKEN_VARIABLE]))->getSettingsHtml())
			->toContain('name="healthToken" value="' . HEALTH_TOKEN_VARIABLE . '"')
			->not->toContain('id="healthToken-field"');
	});

	it('renders the health token field with the endpoint in the pro edition', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;

		$html = (new Adapter(['healthToken' => HEALTH_TOKEN_VARIABLE]))->getSettingsHtml();

		expect($html)
			->toContain('id="healthToken-field"')
			->toContain('Health Token')
			->toContain(htmlspecialchars(Health::url()))
			->toContain('At least 16 characters')
			->not->toContain('type="hidden" name="healthToken"');
	});

	it('renders the sites in the pro edition', function () {
		Plugin::getInstance()->edition = Plugin::EDITION_PRO;

		expect((new Adapter())->getSettingsHtml())
			->toContain('id="sites-field"')
			->toContain('<span class="lettermint-pro-badge">Pro</span>')
			->toContain('Route slug and tags for emails of each site.')
			->toContain('This can begin with an environment variable.')
			->toContain('href="https://craftcms.com/docs/5.x/configure.html#control-panel-settings"');
	});
});
