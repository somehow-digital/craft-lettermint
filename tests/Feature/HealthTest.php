<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Health;
use SomehowDigital\Craft\Lettermint\Plugin;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;

const HEALTH_TOKEN = 'health-token-0123456789';

/**
 * The requests of every check with a team token: the ping of the project token, the team and its usage.
 */
const TEAM_REQUESTS = ['/ping', '/team', '/team/usage'];

const HEALTH_RESPONSES = [
	'/ping' => 'pong',
	'/team' => ['id' => 'team-1', 'name' => 'Acme Inc', 'included_volume' => 1000],
	'/team/usage' => [
		'current_period' => ['usage' => 250, 'period_start' => '2020-01-01T00:00:00Z', 'period_end' => '2099-12-31T23:59:59Z'],
	],
	'/stats' => ['totals' => [], 'daily' => []],
	'/projects/project-1' => [
		'id' => 'project-1',
		'name' => 'Website',
		'delivery_mode' => 'live',
		'routes' => [['id' => 'route-1', 'slug' => 'outgoing', 'route_type' => 'transactional', 'is_default' => true]],
		'domains' => [['id' => 'domain-1', 'domain' => 'acme.test']],
	],
	'/domains' => ['data' => [['id' => 'domain-1', 'domain' => 'acme.test', 'status' => 'verified']], 'next_cursor' => null],
];

/**
 * Sets the email settings, with the adapter as the transport.
 */
function healthEmail(array $settings): void
{
	Craft::$app->getProjectConfig()->set('email', [
		'fromEmail' => 'hello@acme.test',
		'transportType' => Adapter::class,
		'transportSettings' => ['token' => 'secret', 'healthToken' => HEALTH_TOKEN, ...$settings],
	]);
}

/**
 * Answers the requests of Lettermint with the given responses by path, and 404 for others.
 *
 * @param array<string,mixed> $responses
 */
function healthResponses(array $responses = HEALTH_RESPONSES): Closure
{
	return static function (RequestInterface $request) use ($responses) {
		$path = substr($request->getUri()->getPath(), strlen('/v1'));

		return isset($responses[$path]) ? jsonResponse($responses[$path]) : new Response(404);
	};
}

/**
 * Requests the endpoint and returns the response, with its JSON decoded as `data`.
 *
 * @param array<string,string> $headers
 * @param array<string,string> $query
 * @return array{response:yii\web\Response,data:mixed}
 */
function checkHealth(?array $headers = null, array $query = [], string $method = 'GET'): array
{
	$headers ??= ['Authorization' => 'Bearer ' . HEALTH_TOKEN];

	$_SERVER['REQUEST_METHOD'] = $method;

	$request = Craft::$app->getRequest();
	$request->setQueryParams($query);

	foreach ($headers as $name => $value) {
		$request->getHeaders()->set($name, $value);
	}

	try {
		Craft::$app->runAction(Health::ACTION);
	} finally {
		unset($_SERVER['REQUEST_METHOD']);
	}

	$response = Craft::$app->getResponse();

	return ['response' => $response, 'data' => $response->data];
}

/**
 * The status of each check, by ID.
 *
 * @return array<string,string>
 */
function checkStatuses(array $data): array
{
	return array_column($data['checks'], 'status', 'id');
}

beforeEach(function () {
	Plugin::getInstance()->edition = Plugin::EDITION_PRO;
});

it('has an endpoint on the primary site', function () {
	expect(Health::url())
		->toStartWith('https://example.test/')
		->toEndWith('actions/lettermint/health');
});

it('reports a project token that Lettermint accepts', function () {
	healthEmail([]);
	$this->http(healthResponses());

	['response' => $response, 'data' => $data] = checkHealth();

	expect($response->getStatusCode())->toBe(200);
	expect($response->getHeaders()->get('Cache-Control'))->toBe('no-store');
	expect($data['status'])->toBe('ok');
	expect($data['date'])->toBeString();
	expect($data['usage'])->toBeNull();
	expect($data['checks'])->toBe([
		['id' => 'token', 'status' => 'ok', 'text' => 'Lettermint accepts the project API token.'],
	]);
});

it('takes the token from the query parameter', function () {
	healthEmail([]);
	$this->http(healthResponses());

	expect(checkHealth([], ['key' => HEALTH_TOKEN])['response']->getStatusCode())->toBe(200);
});

it('resolves the token from an environment variable', function () {
	$this->env(['LETTERMINT_HEALTH_TOKEN' => HEALTH_TOKEN]);
	healthEmail(['healthToken' => '$LETTERMINT_HEALTH_TOKEN']);
	$this->http(healthResponses());

	expect(checkHealth()['response']->getStatusCode())->toBe(200);
});

it('rejects requests without the token', function (array $headers, array $query) {
	healthEmail([]);
	$requests = $this->http(healthResponses());

	expect(fn () => checkHealth($headers, $query))->toThrow(UnauthorizedHttpException::class);
	expect($requests)->toHaveCount(0);
})->with([
	'none' => [[], []],
	'wrong header' => [['Authorization' => 'Bearer wrong'], []],
	'other scheme' => [['Authorization' => 'Basic ' . HEALTH_TOKEN], []],
	'wrong query' => [[], ['key' => 'wrong']],
	'craft token query' => [[], ['token' => HEALTH_TOKEN]],
]);

it('fails when Lettermint rejects the project token', function () {
	healthEmail([]);
	$this->http(new Response(401));

	['response' => $response, 'data' => $data] = checkHealth();

	expect($response->getStatusCode())->toBe(503);
	expect($data['status'])->toBe('error');
	expect($data['checks'][0]['text'])->toBe('Lettermint rejects the project API token.');
});

it('fails when Lettermint cannot be reached', function () {
	healthEmail([]);
	$this->http(static fn (RequestInterface $request) => throw new ConnectException('Unreachable', $request));

	['response' => $response, 'data' => $data] = checkHealth();

	expect($response->getStatusCode())->toBe(503);
	expect($data['checks'][0]['text'])->toBe('Lettermint could not be reached.');
});

it('checks the team, usage and project with a team token', function () {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'project-1']);
	$this->http(healthResponses());

	['response' => $response, 'data' => $data] = checkHealth();

	expect($response->getStatusCode())->toBe(200);
	expect($data['status'])->toBe('ok');
	expect(checkStatuses($data))->toBe([
		'token' => 'ok',
		'teamToken' => 'ok',
		'usage' => 'ok',
		'delivery' => 'ok',
		'sender' => 'ok',
		'route' => 'ok',
	]);
	expect($data['usage'])->toBe(['used' => 250, 'included' => 1000, 'share' => 0.25]);
});

it('warns about problems that do not stop sending', function () {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'project-1']);

	$responses = HEALTH_RESPONSES;
	$responses['/projects/project-1']['delivery_mode'] = 'sandbox';
	$this->http(healthResponses($responses));

	['response' => $response, 'data' => $data] = checkHealth();

	expect($response->getStatusCode())->toBe(200);
	expect($data['status'])->toBe('warning');
	expect(checkStatuses($data)['delivery'])->toBe('warning');
});

it('fails for a route that is not in the project', function () {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'project-1', 'route' => 'missing']);
	$this->http(healthResponses());

	['response' => $response, 'data' => $data] = checkHealth();

	expect($response->getStatusCode())->toBe(503);
	expect(checkStatuses($data)['route'])->toBe('error');
});

it('warns about the usage of the plan', function (int $used, string $status, string $text) {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'project-1']);

	$responses = HEALTH_RESPONSES;
	$responses['/team/usage']['current_period']['usage'] = $used;
	$this->http(healthResponses($responses));

	$usage = array_column(checkHealth()['data']['checks'], null, 'id')['usage'];

	expect($usage['status'])->toBe($status);
	expect($usage['text'])->toBe($text);
})->with([
	'below' => [899, 'ok', '899 of 1000 emails of the plan were sent this period.'],
	'nearly' => [900, 'warning', 'The monthly volume of the plan is nearly used up, with 900 of 1000 emails.'],
	'used up' => [1200, 'warning', 'The monthly volume of the plan is used up, with 1200 of 1000 emails.'],
]);

it('warns about a team token that Lettermint rejects', function () {
	healthEmail(['teamToken' => 'team-secret']);
	$this->http(static fn (RequestInterface $request) => $request->getHeaderLine('Authorization') === 'Bearer team-secret'
		? new Response(401)
		: healthResponses()($request));

	['response' => $response, 'data' => $data] = checkHealth();

	expect($response->getStatusCode())->toBe(200);
	expect($data['status'])->toBe('warning');
	expect(checkStatuses($data))->toBe(['token' => 'ok', 'teamToken' => 'warning']);
	expect($data['usage'])->toBeNull();
});

it('warns about a project that is not in the team', function () {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'unknown']);
	$this->http(healthResponses());

	$project = array_column(checkHealth()['data']['checks'], null, 'id')['project'];

	expect($project)->toBe(['id' => 'project', 'status' => 'warning', 'text' => 'The project “unknown” is not a project of the team.']);
});

it('warns about a project that could not be loaded', function () {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'project-1']);
	$this->http(static fn (RequestInterface $request) => $request->getUri()->getPath() === '/v1/projects/project-1'
		? new Response(500)
		: healthResponses()($request));

	['data' => $data] = checkHealth();

	expect(checkStatuses($data)['project'])->toBe('warning');
	expect(checkStatuses($data))->not->toHaveKey('delivery');
});

/**
 * The paths of the requests to Lettermint, without the version.
 *
 * @param ArrayObject<int,RequestInterface> $requests
 * @return list<string>
 */
function requestPaths(ArrayObject $requests): array
{
	return array_map(static fn (RequestInterface $request) => substr($request->getUri()->getPath(), strlen('/v1')), $requests->getArrayCopy());
}

it('only requests what the checks need', function () {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'project-1']);
	$requests = $this->http(healthResponses());

	checkHealth();

	expect(requestPaths($requests))->toBe([...TEAM_REQUESTS, '/projects/project-1', '/domains']);
	expect(urldecode($requests[3]->getUri()->getQuery()))->toBe('include=routes,domains');
});

it('requests no project without a project', function () {
	healthEmail(['teamToken' => 'team-secret']);
	$requests = $this->http(healthResponses());

	['data' => $data] = checkHealth();

	expect(requestPaths($requests))->toBe(TEAM_REQUESTS);
	expect(checkStatuses($data))->not->toHaveKey('delivery');
});

it('lists no domains without a project', function () {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'unknown']);
	$requests = $this->http(healthResponses());

	checkHealth();

	expect(requestPaths($requests))->toBe([...TEAM_REQUESTS, '/projects/unknown']);
});

it('stops requesting the team API once it rejects the team token', function () {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'project-1']);
	$requests = $this->http(static fn (RequestInterface $request) => $request->getHeaderLine('Authorization') === 'Bearer team-secret'
		? new Response(401)
		: healthResponses()($request));

	checkHealth();

	// The usage is not requested once the team is rejected.
	expect(requestPaths($requests))->toBe(array_slice(TEAM_REQUESTS, 0, 2));
});

it('caches the report for a minute', function () {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'project-1']);
	$requests = $this->http(healthResponses());

	$first = checkHealth()['data'];
	$count = count($requests);
	$second = checkHealth()['data'];

	expect($count)->toBeGreaterThan(0);
	expect($requests)->toHaveCount($count);
	expect($second)->toBe($first);
});

it('checks again when the settings change', function () {
	healthEmail([]);
	$requests = $this->http(healthResponses());

	checkHealth();
	healthEmail(['route' => 'outgoing']);
	checkHealth();

	expect($requests)->toHaveCount(2);
});

it('answers HEAD requests', function () {
	healthEmail([]);
	$this->http(healthResponses());

	expect(checkHealth(method: 'HEAD')['response']->getStatusCode())->toBe(200);
});

it('only answers GET and HEAD requests', function () {
	healthEmail([]);

	expect(fn () => checkHealth(method: 'POST'))->toThrow(MethodNotAllowedHttpException::class);
});

it('has no endpoint in the standard edition', function () {
	Plugin::getInstance()->edition = Plugin::EDITION_STANDARD;
	healthEmail([]);

	expect(fn () => checkHealth())->toThrow(NotFoundHttpException::class);
});

it('has no endpoint without a token', function (?string $token) {
	healthEmail(['healthToken' => $token]);

	expect(fn () => checkHealth())->toThrow(NotFoundHttpException::class);
})->with([
	'none' => [null],
	'empty' => [''],
	'missing environment variable' => ['$LETTERMINT_MISSING'],
]);

it('has no endpoint when Lettermint is not the transport', function () {
	expect(fn () => checkHealth())->toThrow(NotFoundHttpException::class);
});

it('requires a token that is long enough', function () {
	$this->env(['LETTERMINT_SHORT_TOKEN' => 'short']);

	$adapter = new Adapter(['token' => 'secret', 'healthToken' => '$LETTERMINT_SHORT_TOKEN']);

	expect($adapter->validate())->toBeFalse();
	expect($adapter->getFirstError('healthToken'))->toBe('The health token must be at least 16 characters long.');

	$adapter->healthToken = 'health token 0123456789';

	expect($adapter->validate())->toBeFalse();
	expect($adapter->getFirstError('healthToken'))->toBe('The health token cannot contain spaces.');

	$adapter->healthToken = HEALTH_TOKEN;

	expect($adapter->validate())->toBeTrue();
});
