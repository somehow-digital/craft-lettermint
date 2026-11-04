<?php

namespace SomehowDigital\Craft\Lettermint;

use DateTimeImmutable;
use DateTimeZone;
use Lettermint\ApiObject;
use Lettermint\Exceptions\AuthenticationException;
use Lettermint\Exceptions\LettermintConfigException;
use Lettermint\Exceptions\LettermintException;
use Lettermint\Exceptions\NotFoundException;
use Lettermint\Exceptions\PermissionException;
use Lettermint\Lettermint;

/**
 * Reads the team, its usage, statistics, projects and domains from the team API of Lettermint.
 */
class TeamApi
{
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

	/**
	 * The client of the SDK, created with the first request.
	 */
	private ?Lettermint $client = null;

	/**
	 * Whether Lettermint rejected the token, which skips the remaining requests.
	 */
	private bool $rejected = false;

	public function __construct(
		#[\SensitiveParameter]
		private readonly string $token,
	) {
	}

	/**
	 * Requests all sections.
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

		$sections = [
			'team' => $this->read(static fn (Lettermint $client) => $client->team->retrieve()),
			'usage' => $this->read(static fn (Lettermint $client) => $client->team->usage()),
			'stats' => $this->read(static fn (Lettermint $client) => $client->stats->retrieve([
				'from' => $today->modify(sprintf('-%d days', self::STATS_DAYS - 1))->format('Y-m-d'),
				'to' => $today->format('Y-m-d'),
			])),
			'projects' => $this->listProjects(),
			'domains' => $this->read(static fn (Lettermint $client) => $client->domains->list([
				'page' => ['size' => self::PAGE_SIZE],
				'sort' => ['domain'],
			])),
			'project' => null,
		];

		if ($projectId === null) {
			$projects = $sections['projects']['data']['data'] ?? null;

			// The project of a team with a single project is the project of the token.
			if (is_array($projects) && count($projects) === 1 && is_string($projects[0]['id'] ?? null)) {
				$projectId = $projects[0]['id'];
			}
		}

		if ($projectId !== null) {
			$sections['project'] = $this->read(static fn (Lettermint $client) => $client->projects->retrieve($projectId, [
				'include' => ['routes', 'domains', 'messageStats'],
			]));
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
		$list = [];

		foreach ($this->listProjects()['data']['data'] ?? [] as $project) {
			if (is_string($project['id'] ?? null)) {
				$list[] = ['id' => $project['id'], 'name' => (string) ($project['name'] ?? $project['id'])];
			}
		}

		return $list;
	}

	/**
	 * @return array{data:?array<mixed>,error:?string}
	 */
	private function listProjects(): array
	{
		return $this->read(static fn (Lettermint $client) => $client->projects->list([
			'page' => ['size' => self::PAGE_SIZE],
			'sort' => ['name'],
		]));
	}

	/**
	 * Reads a section with the client, as the object that Lettermint sent.
	 *
	 * @param callable(Lettermint):ApiObject $request
	 * @return array{data:?array<mixed>,error:?string}
	 */
	private function read(callable $request): array
	{
		if ($this->rejected) {
			return ['data' => null, 'error' => 'invalid'];
		}

		try {
			$this->client ??= new Lettermint(teamToken: $this->token, timeout: self::TIMEOUT, httpClient: Adapter::httpClient());

			return ['data' => $request($this->client)->toArray(), 'error' => null];
		} catch (AuthenticationException|LettermintConfigException) {
			// The SDK refuses tokens that cannot be sent as a header, such as ones with spaces.
			$this->rejected = true;

			return ['data' => null, 'error' => 'invalid'];
		} catch (PermissionException) {
			return ['data' => null, 'error' => 'forbidden'];
		} catch (NotFoundException) {
			return ['data' => null, 'error' => 'missing'];
		} catch (LettermintException) {
			return ['data' => null, 'error' => 'unavailable'];
		}
	}
}
