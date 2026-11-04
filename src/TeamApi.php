<?php

namespace SomehowDigital\Craft\Lettermint;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads the team, its usage, statistics, projects and domains from the team API of Lettermint.
 */
class TeamApi
{
	/**
	 * The base URL of the team API of Lettermint.
	 */
	private const BASE_URL = 'https://api.lettermint.co/v1';

	/**
	 * The seconds to wait for Lettermint, which keeps the pages from hanging.
	 */
	private const TIMEOUT = 10;

	/**
	 * The days of message statistics, up to and including today.
	 */
	public const STATS_DAYS = 28;

	/**
	 * The most projects and domains that are listed.
	 */
	public const PAGE_SIZE = 100;

	public function __construct(
		private readonly string $token,
	) {
	}

	/**
	 * Requests all sections at once, so their requests run concurrently.
	 *
	 * Each section holds either its `data` or an `error`, which is `invalid` when Lettermint rejects the token,
	 * `forbidden` when the token lacks the ability for the section, `missing` when the project does not exist
	 * and `unavailable` otherwise.
	 *
	 * The `project` section holds the given project, or the project of a team with a single project,
	 * and is null without either.
	 *
	 * @return array{
	 *     team:array{data:?array<mixed>,error:?string},
	 *     usage:array{data:?array<mixed>,error:?string},
	 *     stats:array{data:?array<mixed>,error:?string},
	 *     projects:array{data:?array<mixed>,error:?string},
	 *     domains:array{data:?array<mixed>,error:?string},
	 *     project:array{data:?array<mixed>,error:?string}|null,
	 * }
	 */
	public function overview(?string $projectId = null): array
	{
		$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

		// The responses are created before any is read, which lets the client send the requests concurrently.
		$responses = [
			'team' => $this->request('/team'),
			'usage' => $this->request('/team/usage'),
			'stats' => $this->request('/stats', [
				'from' => $today->modify(sprintf('-%d days', self::STATS_DAYS - 1))->format('Y-m-d'),
				'to' => $today->format('Y-m-d'),
			]),
			'projects' => $this->request('/projects', ['page[size]' => self::PAGE_SIZE, 'sort' => 'name']),
			'domains' => $this->request('/domains', ['page[size]' => self::PAGE_SIZE, 'sort' => 'domain']),
		];

		if ($projectId !== null) {
			$responses['project'] = $this->project($projectId);
		}

		$sections = array_map(self::read(...), $responses) + ['project' => null];

		if ($projectId === null) {
			$projects = $sections['projects']['data']['data'] ?? null;

			// The project of a team with a single project is the project of the token.
			if (is_array($projects) && count($projects) === 1 && is_string($projects[0]['id'] ?? null)) {
				$sections['project'] = self::read($this->project($projects[0]['id']));
			}
		}

		return $sections;
	}

	/**
	 * The projects of the team, by name, or an empty list when they could not be loaded.
	 *
	 * @return list<array{id:string,name:string}>
	 */
	public function projects(): array
	{
		$projects = self::read($this->request('/projects', ['page[size]' => self::PAGE_SIZE, 'sort' => 'name']));
		$list = [];

		foreach ($projects['data']['data'] ?? [] as $project) {
			if (is_string($project['id'] ?? null)) {
				$list[] = ['id' => $project['id'], 'name' => (string) ($project['name'] ?? $project['id'])];
			}
		}

		return $list;
	}

	private function project(string $id): ?ResponseInterface
	{
		return $this->request('/projects/' . rawurlencode($id), ['include' => 'routes,domains,messageStats']);
	}

	private function request(string $path, array $query = []): ?ResponseInterface
	{
		try {
			return Adapter::httpClient()->request('GET', self::BASE_URL . $path, [
				'headers' => [
					'authorization' => "Bearer {$this->token}",
					'accept' => 'application/json',
				],
				'query' => $query,
				'timeout' => self::TIMEOUT,
				'max_duration' => self::TIMEOUT,
				'max_redirects' => 0,
			]);
		} catch (ExceptionInterface) {
			return null;
		}
	}

	/**
	 * @return array{data:?array<mixed>,error:?string}
	 */
	private static function read(?ResponseInterface $response): array
	{
		if ($response === null) {
			return ['data' => null, 'error' => 'unavailable'];
		}

		try {
			$error = match ($response->getStatusCode()) {
				200 => null,
				401 => 'invalid',
				403 => 'forbidden',
				404 => 'missing',
				default => 'unavailable',
			};

			if ($error !== null) {
				return ['data' => null, 'error' => $error];
			}

			return ['data' => $response->toArray(), 'error' => null];
		} catch (ExceptionInterface) {
			return ['data' => null, 'error' => 'unavailable'];
		}
	}
}
