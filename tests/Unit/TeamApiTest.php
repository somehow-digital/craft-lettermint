<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SomehowDigital\Craft\Lettermint\TeamApi;
use yii\di\Container;

/** The paths of the team and its usage at Lettermint. */
const TEAM_PATH = '/v1/team';
const TEAM_USAGE_PATH = '/v1/team/usage';

it('reads all sections with the team token', function (): void {
	$requests = $this->http(static fn(RequestInterface $request): Response => jsonResponse(['path' => $request->getUri()->getPath()]));

	$sections = (new TeamApi('team-secret'))->overview();

	expect(array_keys($sections))->toBe(['team', 'usage', 'project', 'domains', 'stats']);
	// Without a project, only the team and its usage are requested, and never the projects of the team.
	expect($sections['project'])->toBeNull();
	expect($sections['domains'])->toBeNull();
	expect($sections['stats'])->toBeNull();
	expect($sections['team'])->toBe(['data' => ['path' => TEAM_PATH], 'error' => null]);
	expect($sections['usage']['data'])->toBe(['path' => TEAM_USAGE_PATH]);
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

	(new TeamApi('team-secret'))->overview('project-1');

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

it('reports the errors of each section', function (): void {
	$this->http(static fn(RequestInterface $request): Response => match ($request->getUri()->getPath()) {
		TEAM_PATH => jsonResponse(['name' => 'Acme']),
		TEAM_USAGE_PATH => jsonResponse(['error' => ['code' => 'FORBIDDEN', 'message' => 'Forbidden.']], 403),
		'/v1/stats' => new Response(500),
		'/v1/domains' => throw new ConnectException('Timeout.', $request),
		'/v1/projects/project-1' => jsonResponse(['id' => 'project-1']),
		default => new Response(404),
	});

	$sections = (new TeamApi('team-secret'))->overview('project-1');

	expect($sections['team'])->toBe(['data' => ['name' => 'Acme'], 'error' => null]);
	expect($sections['usage'])->toBe(['data' => null, 'error' => 'forbidden']);
	expect($sections['stats'])->toBe(['data' => null, 'error' => 'unavailable']);
	expect($sections['domains'])->toBe(['data' => null, 'error' => 'unavailable']);
});

it('reports a rejected token as invalid', function (): void {
	$this->http(jsonResponse(['message' => 'Unauthenticated.'], 401));

	foreach (array_filter((new TeamApi('team-secret'))->overview('project-1')) as $section) {
		expect($section)->toBe(['data' => null, 'error' => 'invalid']);
	}
});

it('stops requesting once the token is rejected', function (): void {
	$requests = $this->http(jsonResponse(['message' => 'Unauthenticated.'], 401));

	(new TeamApi('team-secret'))->overview('project-1');

	expect($requests)->toHaveCount(1);
});

it('reports a token that cannot be sent as invalid without a request', function (): void {
	$requests = $this->http(jsonResponse([]));

	foreach (array_filter((new TeamApi('team secret'))->overview()) as $section) {
		expect($section)->toBe(['data' => null, 'error' => 'invalid']);
	}

	expect($requests)->toHaveCount(0);
});

it('reads the given project with its routes and domains', function (): void {
	$requests = $this->http(static fn(RequestInterface $request): Response => str_contains($request->getUri()->getPath(), '/projects/project-1')
		? jsonResponse(['id' => 'project-1', 'name' => 'Website'])
		: jsonResponse(['data' => [['id' => 'project-1'], ['id' => 'project-2']]]));

	$sections = (new TeamApi('team-secret'))->overview('project-1');

	expect($sections['project'])->toBe(['data' => ['id' => 'project-1', 'name' => 'Website'], 'error' => null]);
	expect(array_map(static fn(RequestInterface $request): string => urldecode((string) $request->getUri()), $requests->getArrayCopy()))
		->toContain('https://api.lettermint.co/v1/projects/project-1?include=routes,domains');
});

it('reports a project that does not exist as missing', function (): void {
	$requests = $this->http(static fn(RequestInterface $request): Response => str_contains($request->getUri()->getPath(), '/projects/unknown')
		? new Response(404)
		: jsonResponse([]));

	$sections = (new TeamApi('team-secret'))->overview('unknown');

	expect($sections['project'])->toBe(['data' => null, 'error' => 'missing']);
	expect($sections['domains'])->toBeNull();
	expect($sections['stats'])->toBeNull();
	expect(array_map(static fn(RequestInterface $request): string => $request->getUri()->getPath(), $requests->getArrayCopy()))
		->toBe([TEAM_PATH, TEAM_USAGE_PATH, '/v1/projects/unknown']);
});

it('creates one client for all sections', function (): void {
	/** @var ArrayObject<int,RequestInterface> $later */
	$later = new ArrayObject();

	$requests = $this->http(static function () use ($later): Response {
		// A client created from here on would send its requests elsewhere.
		Craft::$container->set(ClientInterface::class, new Client(['handler' => static function (RequestInterface $request) use ($later): Response {
			$later[] = $request;

			return jsonResponse([]);
		}]));

		return jsonResponse([]);
	});

	(new TeamApi('team-secret'))->overview('project-1');

	expect(count($requests))->toBeGreaterThan(1);
	expect($later)->toHaveCount(0);
});

it('sends the requests with the Guzzle client of Craft when none is set', function (): void {
	/** @var ArrayObject<int,RequestInterface> $requests */
	$requests = new ArrayObject();

	Craft::$container->clear(ClientInterface::class);
	// Craft creates its Guzzle client through the container, with its settings as the only parameter.
	Craft::$container->set(Client::class, static fn(Container $container, array $params): Client => new Client([
		...assertArray($params[0] ?? null),
		'handler' => static function (RequestInterface $request) use ($requests): Response {
			$requests[] = $request;

			return jsonResponse([]);
		},
	]));

	// Craft still sets its user agent with a function that Guzzle deprecated.
	set_error_handler(static fn(int $level, string $message): bool => str_contains($message, 'default_user_agent()'), E_USER_DEPRECATED);

	try {
		(new TeamApi('team-secret'))->overview();
	} finally {
		restore_error_handler();
	}

	expect($requests)->not->toBeEmpty();
});
