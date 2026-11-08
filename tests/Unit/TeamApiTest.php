<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SomehowDigital\Craft\Lettermint\enums\ApiError;
use SomehowDigital\Craft\Lettermint\models\ApiResult;
use SomehowDigital\Craft\Lettermint\models\Domain;
use SomehowDigital\Craft\Lettermint\models\Period;
use SomehowDigital\Craft\Lettermint\models\Project;
use SomehowDigital\Craft\Lettermint\models\Route;
use SomehowDigital\Craft\Lettermint\models\Stats;
use SomehowDigital\Craft\Lettermint\models\StatsTotals;
use SomehowDigital\Craft\Lettermint\models\Team;
use yii\di\Container;

/** Team and usage paths. */
const TEAM_PATH = '/v1/team';
const TEAM_USAGE_PATH = '/v1/team/usage';

it('reads all results with the team token', function (): void {
	$requests = $this->http(static fn(RequestInterface $request): Response => jsonResponse(['path' => $request->getUri()->getPath()]));

	$api = lettermintApi()->teamApi('team-secret', withStats: true);

	// Without a project, only the team and usage are requested.
	expect($api->project)->toBeNull();
	expect($api->stats)->toBeNull();
	expect($api->team)->toEqual(ApiResult::loaded(new Team()));
	expect($api->usage)->toEqual(ApiResult::loaded(null));
	expect(array_map(static fn(RequestInterface $request): string => $request->getUri()->getPath(), $requests->getArrayCopy()))
		->toBe([TEAM_PATH, TEAM_USAGE_PATH]);

	foreach ($requests as $request) {
		expect($request->getMethod())->toBe('GET');
		expect((string) $request->getUri())->toStartWith('https://api.lettermint.co/v1/');
		expect($request->getHeaderLine('Authorization'))->toBe('Bearer team-secret');
	}
});

it('requests the project with its domains and statistics of the last 28 days', function (): void {
	$requests = $this->http(static fn(RequestInterface $request): Response => jsonResponse($request->getUri()->getPath() === '/v1/projects/project-1' ? ['id' => 'project-1'] : []));

	lettermintApi()->teamApi('team-secret', 'project-1', withStats: true);

	$urls = array_map(static fn(RequestInterface $request): string => urldecode((string) $request->getUri()), $requests->getArrayCopy());
	$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
	$from = $today->modify('-27 days')->format('Y-m-d');

	expect($urls)->toContain("https://api.lettermint.co/v1/stats?from={$from}&to={$today->format('Y-m-d')}&project_id=project-1");
	expect($urls)->toBe([
		'https://api.lettermint.co/v1/team',
		'https://api.lettermint.co/v1/team/usage',
		'https://api.lettermint.co/v1/projects/project-1?include=routes,domains',
		'https://api.lettermint.co/v1/domains?page[size]=100&sort=domain&filter[project]=project-1',
		"https://api.lettermint.co/v1/stats?from={$from}&to={$today->format('Y-m-d')}&project_id=project-1",
	]);
});

it('requests the statistics only when they are asked for', function (): void {
	$requests = $this->http(static fn(RequestInterface $request): Response => jsonResponse($request->getUri()->getPath() === '/v1/projects/project-1' ? ['id' => 'project-1'] : []));

	$api = lettermintApi()->teamApi('team-secret', 'project-1');

	expect($api->stats)->toBeNull();
	expect($api->project)->toEqual(ApiResult::loaded(new Project('project-1')));
	expect(array_map(static fn(RequestInterface $request): string => $request->getUri()->getPath(), $requests->getArrayCopy()))
		->toBe([TEAM_PATH, TEAM_USAGE_PATH, '/v1/projects/project-1', '/v1/domains']);
});

it('reports the errors of each result', function (): void {
	$this->http(static fn(RequestInterface $request): Response => match ($request->getUri()->getPath()) {
		TEAM_PATH => jsonResponse(['name' => 'Acme']),
		TEAM_USAGE_PATH => jsonResponse(['error' => ['code' => 'FORBIDDEN', 'message' => 'Forbidden.']], 403),
		'/v1/stats' => new Response(500),
		'/v1/domains' => throw new ConnectException('Timeout.', $request),
		'/v1/projects/project-1' => jsonResponse(['id' => 'project-1']),
		default => new Response(404),
	});

	$api = lettermintApi()->teamApi('team-secret', 'project-1', withStats: true);

	expect($api->team)->toEqual(ApiResult::loaded(new Team('Acme')));
	expect($api->usage)->toEqual(ApiResult::failed(ApiError::Forbidden));
	expect($api->stats)->toEqual(ApiResult::failed(ApiError::Unavailable));
	// Without the domain list, statuses are unknown.
	expect($api->project)->toEqual(ApiResult::loaded(new Project('project-1')));
});

it('reads the status of the listed domains as the data of the plugin', function (): void {
	$this->http(teamApiResponses([
		...TEAM_API_RESPONSES,
		// Empty means no status, as for other fields.
		'/domains' => ['data' => [['id' => 'domain-1', 'domain' => 'acme.test', 'status' => '']]],
	]));

	$api = lettermintApi()->teamApi('team-secret', 'project-1');

	expect($api->project?->data?->domains)->toEqual([new Domain('domain-1', 'acme.test')]);
	// Object comparison is loose, so check the status strictly.
	expect($api->project?->data?->domains[0]->status ?? 'none')->toBe('none');
});

it('requests no domains for a project without an ID', function (): void {
	$requests = $this->http(static fn(RequestInterface $request): Response => jsonResponse($request->getUri()->getPath() === '/v1/projects/project-1' ? ['name' => 'Website'] : []));

	$api = lettermintApi()->teamApi('team-secret', 'project-1', withStats: true);

	expect($api->project)->toEqual(ApiResult::loaded(new Project(name: 'Website')));
	expect($api->stats)->toBeNull();
	expect(array_map(static fn(RequestInterface $request): string => $request->getUri()->getPath(), $requests->getArrayCopy()))
		->toBe([TEAM_PATH, TEAM_USAGE_PATH, '/v1/projects/project-1']);
});

it('rejects the project once the token is rejected for its domains', function (): void {
	$this->http(static fn(RequestInterface $request): Response => match ($request->getUri()->getPath()) {
		'/v1/domains' => jsonResponse(['message' => 'Unauthenticated.'], 401),
		'/v1/projects/project-1' => jsonResponse(['id' => 'project-1']),
		default => jsonResponse([]),
	});

	$api = lettermintApi()->teamApi('team-secret', 'project-1');

	expect($api->project)->toEqual(ApiResult::failed(ApiError::Invalid));
	expect($api->invalid())->toBeTrue();
});

it('reports a rejected token as invalid', function (): void {
	$this->http(jsonResponse(['message' => 'Unauthenticated.'], 401));

	foreach (lettermintApi()->teamApi('team-secret', 'project-1', withStats: true)->requested() as $result) {
		expect($result)->toEqual(ApiResult::failed(ApiError::Invalid));
	}
});

it('stops requesting once the token is rejected', function (): void {
	$requests = $this->http(jsonResponse(['message' => 'Unauthenticated.'], 401));

	lettermintApi()->teamApi('team-secret', 'project-1', withStats: true);

	expect($requests)->toHaveCount(1);
});

it('reports a token that cannot be sent as invalid without a request', function (): void {
	$requests = $this->http(jsonResponse([]));

	foreach (lettermintApi()->teamApi('team secret', withStats: true)->requested() as $result) {
		expect($result)->toEqual(ApiResult::failed(ApiError::Invalid));
	}

	expect($requests)->toHaveCount(0);
});

it('reads the given project with its routes and domains', function (): void {
	$requests = $this->http(static fn(RequestInterface $request): Response => str_contains($request->getUri()->getPath(), '/projects/project-1')
		? jsonResponse(['id' => 'project-1', 'name' => 'Website'])
		: jsonResponse(['data' => [['id' => 'project-1'], ['id' => 'project-2']]]));

	$api = lettermintApi()->teamApi('team-secret', 'project-1', withStats: true);

	expect($api->project)->toEqual(ApiResult::loaded(new Project('project-1', 'Website')));
	expect(array_map(static fn(RequestInterface $request): string => urldecode((string) $request->getUri()), $requests->getArrayCopy()))
		->toContain('https://api.lettermint.co/v1/projects/project-1?include=routes,domains');
});

it('reports a project that does not exist as missing', function (): void {
	$requests = $this->http(static fn(RequestInterface $request): Response => str_contains($request->getUri()->getPath(), '/projects/unknown')
		? new Response(404)
		: jsonResponse([]));

	$api = lettermintApi()->teamApi('team-secret', 'unknown', withStats: true);

	expect($api->project)->toEqual(ApiResult::failed(ApiError::Missing));
	expect($api->stats)->toBeNull();
	expect(array_map(static fn(RequestInterface $request): string => $request->getUri()->getPath(), $requests->getArrayCopy()))
		->toBe([TEAM_PATH, TEAM_USAGE_PATH, '/v1/projects/unknown']);
});

it('creates one client for all results', function (): void {
	/** @var ArrayObject<int,RequestInterface> $later */
	$later = new ArrayObject();

	$requests = $this->http(static function () use ($later): Response {
		// Clients created from here on would use the new responses.
		Craft::$container->set(ClientInterface::class, new Client(['handler' => static function (RequestInterface $request) use ($later): Response {
			$later[] = $request;

			return jsonResponse([]);
		}]));

		return jsonResponse([]);
	});

	lettermintApi()->teamApi('team-secret', 'project-1', withStats: true);

	expect(count($requests))->toBeGreaterThan(1);
	expect($later)->toHaveCount(0);
});

it('sends the requests with the Guzzle client of Craft when none is set', function (): void {
	/** @var ArrayObject<int,RequestInterface> $requests */
	$requests = new ArrayObject();

	Craft::$container->clear(ClientInterface::class);
	// Craft creates Guzzle clients through the container, passing only the config.
	Craft::$container->set(Client::class, static fn(Container $container, array $params): Client => new Client([
		...assertArray($params[0] ?? null),
		'handler' => static function (RequestInterface $request) use ($requests): Response {
			$requests[] = $request;

			return jsonResponse([]);
		},
	]));

	// Craft sets the user agent with a deprecated Guzzle function.
	set_error_handler(static fn(int $level, string $message): bool => str_contains($message, 'default_user_agent()'), E_USER_DEPRECATED);

	try {
		lettermintApi()->teamApi('team-secret', withStats: true);
	} finally {
		restore_error_handler();
	}

	expect($requests)->not->toBeEmpty();
});

it('reads the results as the data of the plugin', function (): void {
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	$api = lettermintApi()->teamApi('team-secret', 'project-1', withStats: true);

	expect($api->team->data)->toEqual(new Team('Acme Inc', 'business', 'growth', 50000, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z'));
	expect($api->usage->data)->toEqual(new Period(12500, '2020-01-01T00:00:00Z', '2099-12-31T23:59:59Z'));
	expect($api->project?->data)->toEqual(new Project(
		'project-1',
		'Website',
		'sandbox',
		'2026-11-03T12:00:00Z',
		'203.0.113.7',
		[new Route('outgoing', 'transactional', true), new Route('newsletter', 'broadcast'), new Route('inbox', 'inbound')],
		// The domain list also includes other projects' domains.
		[new Domain('domain-1', 'acme.test', 'verified')],
	));
	expect($api->stats?->data)->toEqual(new Stats('2026-10-06', '2026-11-04', new StatsTotals(1000, 980, 15, 2, 490)));
});

it('keeps the results when they are cached', function (): void {
	$this->http(teamApiResponses(TEAM_API_RESPONSES));

	$api = lettermintApi()->teamApi('team-secret', 'project-1', withStats: true);

	expect(unserialize(serialize($api)))->toEqual($api);
});
