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
	 * The errors of a section: Lettermint rejects the token, the token lacks the ability for the section,
	 * the project does not exist, or Lettermint could not be read otherwise.
	 */
	public const ERROR_INVALID = 'invalid';
	public const ERROR_FORBIDDEN = 'forbidden';
	public const ERROR_MISSING = 'missing';
	public const ERROR_UNAVAILABLE = 'unavailable';

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
	 * Each section holds either its `data` or an `error`, one of the `ERROR_*` constants.
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
			'domains' => $this->listDomains(),
			'project' => null,
		];

		$projectId ??= self::onlyProject($sections['projects']);

		if ($projectId !== null) {
			$sections['project'] = $this->retrieveProject($projectId, ['routes', 'domains', 'messageStats']);
		}

		return $sections;
	}

	/**
	 * Requests only the sections that the health checks need, in the shape of `overview()`.
	 *
	 * The statistics are left out. The projects are only listed to find the project of a team with a single project,
	 * and the domains are only listed for the status of the domains of the project, once it is loaded.
	 *
	 * @return array{
	 *     team:array{data:?array<mixed>,error:?string},
	 *     usage:array{data:?array<mixed>,error:?string},
	 *     projects:array{data:?array<mixed>,error:?string}|null,
	 *     domains:array{data:?array<mixed>,error:?string}|null,
	 *     project:array{data:?array<mixed>,error:?string}|null,
	 * }
	 */
	public function health(?string $projectId = null): array
	{
		$sections = [
			'team' => $this->read(static fn (Lettermint $client) => $client->team->retrieve()),
			'usage' => $this->read(static fn (Lettermint $client) => $client->team->usage()),
			'projects' => null,
			'domains' => null,
			'project' => null,
		];

		if ($projectId === null) {
			$sections['projects'] = $this->listProjects();
			$projectId = self::onlyProject($sections['projects']);
		}

		if ($projectId !== null) {
			$sections['project'] = $this->retrieveProject($projectId, ['routes', 'domains']);

			if (is_array($sections['project']['data'])) {
				$sections['domains'] = $this->listDomains();
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
	 * @return array{data:?array<mixed>,error:?string}
	 */
	private function listDomains(): array
	{
		return $this->read(static fn (Lettermint $client) => $client->domains->list([
			'page' => ['size' => self::PAGE_SIZE],
			'sort' => ['domain'],
		]));
	}

	/**
	 * @param list<string> $include
	 * @return array{data:?array<mixed>,error:?string}
	 */
	private function retrieveProject(string $projectId, array $include): array
	{
		return $this->read(static fn (Lettermint $client) => $client->projects->retrieve($projectId, ['include' => $include]));
	}

	/**
	 * The ID of the project of a team with a single project, which is the project of the token, or null otherwise.
	 *
	 * @param array{data:?array<mixed>,error:?string} $section The section of the projects.
	 */
	private static function onlyProject(array $section): ?string
	{
		$projects = $section['data']['data'] ?? null;

		return is_array($projects) && count($projects) === 1 && is_string($projects[0]['id'] ?? null) ? $projects[0]['id'] : null;
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
			return self::error(self::ERROR_INVALID);
		}

		try {
			$this->client ??= ClientFactory::team($this->token, self::TIMEOUT);

			return ['data' => $request($this->client)->toArray(), 'error' => null];
		} catch (AuthenticationException|LettermintConfigException) {
			// The SDK refuses tokens that cannot be sent as a header, such as ones with spaces.
			$this->rejected = true;

			return self::error(self::ERROR_INVALID);
		} catch (PermissionException) {
			return self::error(self::ERROR_FORBIDDEN);
		} catch (NotFoundException) {
			return self::error(self::ERROR_MISSING);
		} catch (LettermintException) {
			return self::error(self::ERROR_UNAVAILABLE);
		}
	}

	/**
	 * @return array{data:null,error:string}
	 */
	private static function error(string $error): array
	{
		return ['data' => null, 'error' => $error];
	}
}
