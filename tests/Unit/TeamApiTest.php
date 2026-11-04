<?php

use SomehowDigital\Craft\Lettermint\TeamApi;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

it('reads all sections with the team token', function () {
	$requests = [];

	$this->http(function (string $method, string $url, array $options) use (&$requests) {
		$requests[parse_url($url, PHP_URL_PATH)] = compact('method', 'url', 'options');

		return new JsonMockResponse(['path' => parse_url($url, PHP_URL_PATH)]);
	});

	$sections = (new TeamApi('team-secret'))->overview();

	expect(array_keys($sections))->toBe(['team', 'usage', 'stats', 'projects', 'domains', 'project']);
	expect($sections['project'])->toBeNull();
	expect($sections['team'])->toBe(['data' => ['path' => '/v1/team'], 'error' => null]);
	expect($sections['usage']['data'])->toBe(['path' => '/v1/team/usage']);
	expect(array_keys($requests))->toBe(['/v1/team', '/v1/team/usage', '/v1/stats', '/v1/projects', '/v1/domains']);

	foreach ($requests as $request) {
		expect($request['method'])->toBe('GET');
		expect($request['url'])->toStartWith('https://api.lettermint.co/v1/');
		expect($request['options']['headers'])->toContain('authorization: Bearer team-secret');
	}
});

it('requests the statistics of the last 28 days', function () {
	$urls = [];

	$this->http(function (string $method, string $url) use (&$urls) {
		$urls[] = urldecode($url);

		return new JsonMockResponse([]);
	});

	(new TeamApi('team-secret'))->overview();

	$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
	$from = $today->modify('-27 days')->format('Y-m-d');

	expect($urls)->toContain("https://api.lettermint.co/v1/stats?from={$from}&to={$today->format('Y-m-d')}");
	expect($urls)->toContain('https://api.lettermint.co/v1/projects?page[size]=100&sort=name');
	expect($urls)->toContain('https://api.lettermint.co/v1/domains?page[size]=100&sort=domain');
});

it('reports the errors of each section', function () {
	$this->http(static fn (string $method, string $url) => match (parse_url($url, PHP_URL_PATH)) {
		'/v1/team' => new JsonMockResponse(['name' => 'Acme']),
		'/v1/team/usage' => new JsonMockResponse(['error' => ['code' => 'FORBIDDEN', 'message' => 'Forbidden.']], ['http_code' => 403]),
		'/v1/stats' => new MockResponse('', ['http_code' => 500]),
		'/v1/projects' => new MockResponse('not json'),
		'/v1/domains' => throw new TransportException('Timeout.'),
	});

	$sections = (new TeamApi('team-secret'))->overview();

	expect($sections['team'])->toBe(['data' => ['name' => 'Acme'], 'error' => null]);
	expect($sections['usage'])->toBe(['data' => null, 'error' => 'forbidden']);
	expect($sections['stats'])->toBe(['data' => null, 'error' => 'unavailable']);
	expect($sections['projects'])->toBe(['data' => null, 'error' => 'unavailable']);
	expect($sections['domains'])->toBe(['data' => null, 'error' => 'unavailable']);
});

it('reports a rejected token as invalid', function () {
	$this->http(static fn () => new MockResponse('{"message":"Unauthenticated."}', ['http_code' => 401]));

	foreach (array_filter((new TeamApi('team-secret'))->overview('project-1')) as $section) {
		expect($section)->toBe(['data' => null, 'error' => 'invalid']);
	}
});

it('reads the given project with its routes and domains', function () {
	$urls = [];

	$this->http(function (string $method, string $url) use (&$urls) {
		$urls[] = urldecode($url);

		return str_contains($url, '/projects/project-1')
			? new JsonMockResponse(['id' => 'project-1', 'name' => 'Website'])
			: new JsonMockResponse(['data' => [['id' => 'project-1'], ['id' => 'project-2']]]);
	});

	$sections = (new TeamApi('team-secret'))->overview('project-1');

	expect($sections['project'])->toBe(['data' => ['id' => 'project-1', 'name' => 'Website'], 'error' => null]);
	expect($urls)->toContain('https://api.lettermint.co/v1/projects/project-1?include=routes,domains,messageStats');
});

it('reports a project that does not exist as missing', function () {
	$this->http(static fn (string $method, string $url) => str_contains($url, '/projects/unknown')
		? new MockResponse('', ['http_code' => 404])
		: new JsonMockResponse([]));

	expect((new TeamApi('team-secret'))->overview('unknown')['project'])->toBe(['data' => null, 'error' => 'missing']);
});

it('takes the project of a team with a single project', function () {
	$this->http(static fn (string $method, string $url) => match (parse_url($url, PHP_URL_PATH)) {
		'/v1/projects' => new JsonMockResponse(['data' => [['id' => 'project-1', 'name' => 'Website']]]),
		'/v1/projects/project-1' => new JsonMockResponse(['id' => 'project-1', 'name' => 'Website']),
		default => new JsonMockResponse([]),
	});

	expect((new TeamApi('team-secret'))->overview()['project'])->toBe(['data' => ['id' => 'project-1', 'name' => 'Website'], 'error' => null]);
});

it('takes no project of a team with several projects', function () {
	$this->http(static fn (string $method, string $url) => match (parse_url($url, PHP_URL_PATH)) {
		'/v1/projects' => new JsonMockResponse(['data' => [['id' => 'project-1'], ['id' => 'project-2']]]),
		'/v1/projects/project-1' => throw new LogicException('The project must not be requested.'),
		default => new JsonMockResponse([]),
	});

	expect((new TeamApi('team-secret'))->overview()['project'])->toBeNull();
});

it('lists the projects of the team', function () {
	$this->http(new JsonMockResponse(['data' => [
		['id' => 'project-1', 'name' => 'Website'],
		['id' => 'project-2'],
		['name' => 'Without ID'],
	]]));

	expect((new TeamApi('team-secret'))->projects())->toBe([
		['id' => 'project-1', 'name' => 'Website'],
		['id' => 'project-2', 'name' => 'project-2'],
	]);
});

it('lists no projects when they cannot be loaded', function () {
	$this->http(new MockResponse('', ['http_code' => 500]));

	expect((new TeamApi('team-secret'))->projects())->toBe([]);
});
