<?php

declare(strict_types=1);

use craft\helpers\Json;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SomehowDigital\Craft\Lettermint\enums\CheckStatus;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\helpers\Urls;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\SiteSettings;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * The requests of the team API of every check with a team token: the team and its usage.
 */
const TEAM_REQUESTS = ['/team', '/team/usage'];

/**
 * Requests the endpoint and returns the response, with its JSON decoded as `data`.
 *
 * @param array<string,string>|null $headers
 * @param array<string,string> $query
 * @return array{response:yii\web\Response,data:array{status:string,date:string,checks:list<array{id:string,status:string,text:string}>,usage:?array{used:int,included:?int,share:?float}}}
 */
function checkHealth(?array $headers = null, array $query = [], string $method = 'GET'): array
{
	$headers ??= ['Authorization' => 'Bearer ' . HEALTH_TOKEN];

	$_SERVER['REQUEST_METHOD'] = $method;

	$request = webRequest();
	$request->setQueryParams($query);

	foreach ($headers as $name => $value) {
		$request->getHeaders()->set($name, $value);
	}

	try {
		Craft::$app->runAction(Urls::HEALTH_ACTION);
	} finally {
		unset($_SERVER['REQUEST_METHOD']);
	}

	$response = webResponse();

	/** @var array{status:string,date:string,checks:list<array{id:string,status:string,text:string}>,usage:?array{used:int,included:?int,share:?float}} $data The report of the health checks, as monitoring receives it. */
	$data = Json::decode(Json::encode($response->data));

	return ['response' => $response, 'data' => $data];
}

/**
 * Answers the requests of Lettermint with the default responses, rejecting the ones with the team token.
 *
 * @return Closure(RequestInterface):ResponseInterface
 */
function rejectTeamToken(): Closure
{
	return static fn(RequestInterface $request): ResponseInterface => $request->getHeaderLine('Authorization') === 'Bearer team-secret'
		? new Response(401)
		: healthResponses()($request);
}

/**
 * The status of each check, by ID.
 *
 * @param array{checks:list<array{id:string,status:string,text:string}>,...} $data
 * @return array<string,string>
 */
function checkStatuses(array $data): array
{
	return array_column($data['checks'], 'status', 'id');
}

beforeEach(function (): void {
	edition(Edition::Pro);
});

it('has an endpoint on the primary site', function (): void {
	expect(Urls::health('actions'))
		->toStartWith('https://example.test/')
		->toEndWith('actions/lettermint/health');
});

it('reports a project token that Lettermint accepts', function (): void {
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

it('takes the token from the query parameter', function (): void {
	healthEmail([]);
	$this->http(healthResponses());

	expect(checkHealth([], ['key' => HEALTH_TOKEN])['response']->getStatusCode())->toBe(200);
});

it('resolves the token from an environment variable', function (): void {
	$this->env(['LETTERMINT_HEALTH_TOKEN' => HEALTH_TOKEN]);
	healthEmail(['healthToken' => '$LETTERMINT_HEALTH_TOKEN']);
	$this->http(healthResponses());

	expect(checkHealth()['response']->getStatusCode())->toBe(200);
});

it('rejects requests without the token', function (array $headers, array $query): void {
	/**
	 * @var array<string,string> $headers
	 * @var array<string,string> $query
	 */
	healthEmail([]);
	$requests = $this->http(healthResponses());

	expect(fn(): array => checkHealth($headers, $query))->toThrow(UnauthorizedHttpException::class);
	expect($requests)->toHaveCount(0);
})->with([
	'none' => [[], []],
	'wrong header' => [['Authorization' => 'Bearer wrong'], []],
	'other scheme' => [['Authorization' => 'Basic ' . HEALTH_TOKEN], []],
	'wrong query' => [[], ['key' => 'wrong']],
	'craft token query' => [[], ['token' => HEALTH_TOKEN]],
]);

it('fails when Lettermint rejects the project token', function (): void {
	healthEmail([]);
	$this->http(new Response(401));

	['response' => $response, 'data' => $data] = checkHealth();

	expect($response->getStatusCode())->toBe(503);
	expect($data['status'])->toBe('error');
	expect($data['checks'][0]['text'])->toBe('Lettermint rejects the project API token, or does not allow it from the IP address of this server.');
});

it('fails when Lettermint cannot be reached', function (): void {
	healthEmail([]);
	$this->http(static fn(RequestInterface $request): never => throw new ConnectException('Unreachable', $request));

	['response' => $response, 'data' => $data] = checkHealth();

	expect($response->getStatusCode())->toBe(503);
	expect($data['checks'][0]['text'])->toBe('Lettermint could not be reached.');
});

it('checks the team, usage and project with a team token', function (): void {
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);
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

it('warns about problems that do not stop sending', function (): void {
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);

	$responses = HEALTH_RESPONSES;
	$responses['/projects/project-1']['delivery_mode'] = 'sandbox';
	$this->http(healthResponses($responses));

	['response' => $response, 'data' => $data] = checkHealth();

	expect($response->getStatusCode())->toBe(200);
	expect($data['status'])->toBe('warning');
	expect(checkStatuses($data)['delivery'])->toBe('warning');
});

it('fails for a route that is not in the project', function (): void {
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1', 'route' => 'missing']);
	$this->http(healthResponses());

	['response' => $response, 'data' => $data] = checkHealth();

	expect($response->getStatusCode())->toBe(503);
	expect(checkStatuses($data)['route'])->toBe('error');
});

it('warns about the usage of the plan', function (int $used, string $status, string $text): void {
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);

	$responses = HEALTH_RESPONSES;
	$responses['/team/usage']['current_period']['usage'] = $used;
	$this->http(healthResponses($responses));

	$usage = array_column(checkHealth()['data']['checks'], null, 'id')['usage'];

	expect($usage['status'])->toBe($status);
	expect($usage['text'])->toBe($text);
})->with([
	'below' => [899, 'ok', '899 of 1000 emails of the plan were sent this period.'],
	'nearly' => [900, 'warning', 'The monthly volume of the plan is nearly used up, with 900 of 1000 emails.'],
	'exactly used up' => [1000, 'warning', 'The monthly volume of the plan is used up, with 1000 of 1000 emails.'],
	'used up' => [1200, 'warning', 'The monthly volume of the plan is used up, with 1200 of 1000 emails.'],
]);

it('warns about a team token that Lettermint rejects', function (): void {
	healthEmail(['teamToken' => 'team-secret']);
	$this->http(rejectTeamToken());

	['response' => $response, 'data' => $data] = checkHealth();

	expect($response->getStatusCode())->toBe(200);
	expect($data['status'])->toBe('warning');
	expect(checkStatuses($data))->toBe(['token' => 'ok', 'teamToken' => 'warning']);
	expect($data['usage'])->toBeNull();
});

it('warns about a project that is not in the team', function (): void {
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'unknown']);
	$this->http(healthResponses());

	$project = array_column(checkHealth()['data']['checks'], null, 'id')['project'];

	expect($project)->toBe(['id' => 'project', 'status' => 'warning', 'text' => 'The project “unknown” is not a project of the team.']);
});

it('warns about a project that could not be loaded', function (): void {
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$this->http(static fn(RequestInterface $request) => $request->getUri()->getPath() === '/v1/projects/project-1'
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
	return array_values(array_map(static fn(RequestInterface $request): string => substr($request->getUri()->getPath(), strlen('/v1')), $requests->getArrayCopy()));
}

it('only requests what the checks need', function (): void {
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$requests = $this->http(healthResponses());

	checkHealth();

	expect(requestPaths($requests))->toBe([...TEAM_REQUESTS, '/projects/project-1', '/domains', '/ping']);
	expect(urldecode($requests->getArrayCopy()[2]->getUri()->getQuery()))->toBe('include=routes,domains');
});

it('requests no project without a project', function (): void {
	healthEmail(['teamToken' => 'team-secret']);
	$requests = $this->http(healthResponses());

	['data' => $data] = checkHealth();

	expect(requestPaths($requests))->toBe([...TEAM_REQUESTS, '/ping']);
	expect(checkStatuses($data))->not->toHaveKey('delivery');
});

it('lists no domains without a project', function (): void {
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'unknown']);
	$requests = $this->http(healthResponses());

	checkHealth();

	expect(requestPaths($requests))->toBe([...TEAM_REQUESTS, '/projects/unknown', '/ping']);
});

it('stops requesting the team API once it rejects the team token', function (): void {
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$requests = $this->http(rejectTeamToken());

	checkHealth();

	// The usage is not requested once the team is rejected, while the project token is still pinged.
	expect(requestPaths($requests))->toBe(['/team', '/ping']);
});

it('caches the report for a minute', function (): void {
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$requests = $this->http(healthResponses());

	$first = checkHealth()['data'];
	$count = count($requests);
	$second = checkHealth()['data'];

	expect($count)->toBeGreaterThan(0);
	expect($requests)->toHaveCount($count);
	expect($second)->toBe($first);
});

it('checks again when the settings change', function (): void {
	healthEmail([]);
	$requests = $this->http(healthResponses());

	checkHealth();
	healthEmail(['route' => 'outgoing']);
	checkHealth();

	expect($requests)->toHaveCount(2);
});

it('answers HEAD requests', function (): void {
	healthEmail([]);
	$this->http(healthResponses());

	expect(checkHealth(method: 'HEAD')['response']->getStatusCode())->toBe(200);
});

it('only answers GET and HEAD requests', function (): void {
	healthEmail([]);

	expect(fn(): array => checkHealth(method: 'POST'))->toThrow(MethodNotAllowedHttpException::class);
});

it('has no endpoint in the standard edition', function (): void {
	edition(Edition::Standard);
	healthEmail([]);

	expect(fn(): array => checkHealth())->toThrow(NotFoundHttpException::class);
});

it('has no endpoint without a token', function (?string $token): void {
	healthEmail(['healthToken' => $token]);

	expect(fn(): array => checkHealth())->toThrow(NotFoundHttpException::class);
})->with([
	'none' => [null],
	'empty' => [''],
	'missing environment variable' => ['$LETTERMINT_MISSING'],
]);

it('has no endpoint when Lettermint is not the transport', function (): void {
	expect(fn(): array => checkHealth())->toThrow(NotFoundHttpException::class);
});

it('requires a token that is long enough', function (): void {
	$this->env(['LETTERMINT_SHORT_TOKEN' => 'short']);

	$adapter = adapter(['token' => 'secret', 'healthToken' => '$LETTERMINT_SHORT_TOKEN']);

	expect($adapter->validate())->toBeFalse();
	expect($adapter->getFirstError('healthToken'))->toBe('The health token must be at least 16 characters long.');

	$adapter->healthToken = 'health token 0123456789';

	expect($adapter->validate())->toBeFalse();
	expect($adapter->getFirstError('healthToken'))->toBe('The health token cannot contain spaces.');

	$adapter->healthToken = HEALTH_TOKEN;

	expect($adapter->validate())->toBeTrue();
});

it('validates no health token whose variable is not set', function (): void {
	// Craft resolves the variables before validating, which leaves the empty token unvalidated.
	$adapter = adapter(['token' => 'secret', 'healthToken' => '$LETTERMINT_MISSING']);

	expect($adapter->validate())->toBeTrue();
});

it('checks nothing more once Lettermint rejects the team token', function (): void {
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$this->http(rejectTeamToken());

	expect(checkStatuses(checkHealth()['data']))->toBe(['token' => 'ok', 'teamToken' => 'warning']);
});

it('checks without a cache', function (): void {
	healthEmail([]);
	$this->http(healthResponses());
	withoutCache();

	expect(checkHealth()['data']['status'])->toBe('ok');
});

it('accepts a bearer token with whitespace around it', function (): void {
	healthEmail([]);
	$this->http(healthResponses());

	expect(checkHealth(['Authorization' => ' Bearer ' . HEALTH_TOKEN . ' '])['response']->getStatusCode())->toBe(200);
});

it('logs rejected requests', function (): void {
	healthEmail([]);

	expect(fn(): array => checkHealth(['Authorization' => 'Bearer wrong']))->toThrow(UnauthorizedHttpException::class);
	expect(loggedWarnings())->toBe(['Rejected a Lettermint health request with a missing or wrong token.']);
});

describe('checks', function (): void {
	it('checks the system email address with its environment variables resolved', function (): void {
		$this->env(['LETTERMINT_SENDER' => 'hello@acme.test']);
		lettermintEmail(['token' => 'secret'], '$LETTERMINT_SENDER');
		$this->http(healthResponses());

		$checks = plugin()->getChecks()->checks(adapter(['token' => 'secret'])->resolve(), TokenStatus::Valid, lettermintApi()->teamApi('team-secret', 'project-1'));

		expect(array_column($checks->projectChecks, 'status', 'id')['sender'])->toBe(CheckStatus::Ok);
	});

	it('checks the routes of all sites, also the disabled ones', function (): void {
		addSite('site-a', 'shop', 'Shop');
		addSite('site-b', 'archive', 'Archive', enabled: false);
		healthEmail([]);
		$this->http(healthResponses());

		$checks = plugin()->getChecks()->checks(new Settings(
			token: 'secret',
			sites: ['site-a' => new SiteSettings(route: 'outgoing'), 'site-b' => new SiteSettings(route: 'outgoing')],
		), TokenStatus::Valid, lettermintApi()->teamApi('team-secret', 'project-1'));

		expect(array_column($checks->projectChecks, 'id'))->toContain('route.shop', 'route.archive');
	});
});
