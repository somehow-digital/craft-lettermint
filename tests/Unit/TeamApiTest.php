<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SomehowDigital\Craft\Lettermint\TeamApi;

it('reads all sections with the team token', function () {
	$requests = $this->http(static fn (RequestInterface $request) => jsonResponse(['path' => $request->getUri()->getPath()]));

	$sections = (new TeamApi('team-secret'))->overview();

	expect(array_keys($sections))->toBe(['team', 'usage', 'stats', 'projects', 'domains', 'project']);
	expect($sections['project'])->toBeNull();
	expect($sections['team'])->toBe(['data' => ['path' => '/v1/team'], 'error' => null]);
	expect($sections['usage']['data'])->toBe(['path' => '/v1/team/usage']);
	expect(array_map(static fn (RequestInterface $request) => $request->getUri()->getPath(), $requests->getArrayCopy()))
		->toBe(['/v1/team', '/v1/team/usage', '/v1/stats', '/v1/projects', '/v1/domains']);

	foreach ($requests as $request) {
		expect($request->getMethod())->toBe('GET');
		expect((string) $request->getUri())->toStartWith('https://api.lettermint.co/v1/');
		expect($request->getHeaderLine('Authorization'))->toBe('Bearer team-secret');
	}
});

it('requests the statistics of the last 28 days', function () {
	$requests = $this->http(jsonResponse([]));

	(new TeamApi('team-secret'))->overview();

	$urls = array_map(static fn (RequestInterface $request) => urldecode((string) $request->getUri()), $requests->getArrayCopy());
	$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
	$from = $today->modify('-27 days')->format('Y-m-d');

	expect($urls)->toContain("https://api.lettermint.co/v1/stats?from={$from}&to={$today->format('Y-m-d')}");
	expect($urls)->toContain('https://api.lettermint.co/v1/projects?page[size]=100&sort=name');
	expect($urls)->toContain('https://api.lettermint.co/v1/domains?page[size]=100&sort=domain');
});

it('reports the errors of each section', function () {
	$this->http(static fn (RequestInterface $request) => match ($request->getUri()->getPath()) {
		'/v1/team' => jsonResponse(['name' => 'Acme']),
		'/v1/team/usage' => jsonResponse(['error' => ['code' => 'FORBIDDEN', 'message' => 'Forbidden.']], 403),
		'/v1/stats' => new Response(500),
		'/v1/projects' => new Response(200, [], 'not json'),
		'/v1/domains' => throw new ConnectException('Timeout.', $request),
	});

	$sections = (new TeamApi('team-secret'))->overview();

	expect($sections['team'])->toBe(['data' => ['name' => 'Acme'], 'error' => null]);
	expect($sections['usage'])->toBe(['data' => null, 'error' => 'forbidden']);
	expect($sections['stats'])->toBe(['data' => null, 'error' => 'unavailable']);
	expect($sections['projects'])->toBe(['data' => null, 'error' => 'unavailable']);
	expect($sections['domains'])->toBe(['data' => null, 'error' => 'unavailable']);
});

it('reports a rejected token as invalid', function () {
	$this->http(jsonResponse(['message' => 'Unauthenticated.'], 401));

	foreach (array_filter((new TeamApi('team-secret'))->overview('project-1')) as $section) {
		expect($section)->toBe(['data' => null, 'error' => 'invalid']);
	}
});

it('stops requesting once the token is rejected', function () {
	$requests = $this->http(jsonResponse(['message' => 'Unauthenticated.'], 401));

	(new TeamApi('team-secret'))->overview('project-1');

	expect($requests)->toHaveCount(1);
});

it('reports a token that cannot be sent as invalid without a request', function () {
	$requests = $this->http(jsonResponse([]));

	foreach (array_filter((new TeamApi('team secret'))->overview()) as $section) {
		expect($section)->toBe(['data' => null, 'error' => 'invalid']);
	}

	expect($requests)->toHaveCount(0);
});

it('reads the given project with its routes and domains', function () {
	$requests = $this->http(static fn (RequestInterface $request) => str_contains($request->getUri()->getPath(), '/projects/project-1')
		? jsonResponse(['id' => 'project-1', 'name' => 'Website'])
		: jsonResponse(['data' => [['id' => 'project-1'], ['id' => 'project-2']]]));

	$sections = (new TeamApi('team-secret'))->overview('project-1');

	expect($sections['project'])->toBe(['data' => ['id' => 'project-1', 'name' => 'Website'], 'error' => null]);
	expect(array_map(static fn (RequestInterface $request) => urldecode((string) $request->getUri()), $requests->getArrayCopy()))
		->toContain('https://api.lettermint.co/v1/projects/project-1?include=routes,domains,messageStats');
});

it('reports a project that does not exist as missing', function () {
	$this->http(static fn (RequestInterface $request) => str_contains($request->getUri()->getPath(), '/projects/unknown')
		? new Response(404)
		: jsonResponse([]));

	expect((new TeamApi('team-secret'))->overview('unknown')['project'])->toBe(['data' => null, 'error' => 'missing']);
});

it('takes the project of a team with a single project', function () {
	$this->http(static fn (RequestInterface $request) => match ($request->getUri()->getPath()) {
		'/v1/projects' => jsonResponse(['data' => [['id' => 'project-1', 'name' => 'Website']]]),
		'/v1/projects/project-1' => jsonResponse(['id' => 'project-1', 'name' => 'Website']),
		default => jsonResponse([]),
	});

	expect((new TeamApi('team-secret'))->overview()['project'])->toBe(['data' => ['id' => 'project-1', 'name' => 'Website'], 'error' => null]);
});

it('takes no project of a team with several projects', function () {
	$this->http(static fn (RequestInterface $request) => match ($request->getUri()->getPath()) {
		'/v1/projects' => jsonResponse(['data' => [['id' => 'project-1'], ['id' => 'project-2']]]),
		'/v1/projects/project-1' => throw new LogicException('The project must not be requested.'),
		default => jsonResponse([]),
	});

	expect((new TeamApi('team-secret'))->overview()['project'])->toBeNull();
});

it('lists the projects of the team', function () {
	$this->http(jsonResponse(['data' => [
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
	$this->http(new Response(500));

	expect((new TeamApi('team-secret'))->projects())->toBe([]);
});
