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
 * Reads the team, its usage, and the project with its domains and statistics from the team API of Lettermint.
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
	 * The most domains of the project that are listed.
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
	 * Only the team, its usage and the given project are requested, never the other projects of the team. The
	 * `project` section holds the given project, and the `domains` and `stats` sections its domains and statistics,
	 * which are null without a project, or when it could not be loaded.
	 *
	 * @return array{
	 *     team:array{data:?array<mixed>,error:?string},
	 *     usage:array{data:?array<mixed>,error:?string},
	 *     project:array{data:?array<mixed>,error:?string}|null,
	 *     domains:array{data:?array<mixed>,error:?string}|null,
	 *     stats:array{data:?array<mixed>,error:?string}|null,
	 * }
	 */
	public function overview(?string $projectId = null): array
	{
		$sections = $this->health($projectId);
		$sections['stats'] = null;

		if (null !== $id = self::loadedId($sections['project'])) {
			$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

			$sections['stats'] = $this->read(static fn (Lettermint $client) => $client->stats->retrieve([
				'from' => $today->modify(sprintf('-%d days', self::STATS_DAYS - 1))->format('Y-m-d'),
				'to' => $today->format('Y-m-d'),
				'project_id' => $id,
			]));
		}

		return $sections;
	}

	/**
	 * Requests only the sections that the health checks need, in the shape of `overview()` without the statistics.
	 *
	 * @return array{
	 *     team:array{data:?array<mixed>,error:?string},
	 *     usage:array{data:?array<mixed>,error:?string},
	 *     project:array{data:?array<mixed>,error:?string}|null,
	 *     domains:array{data:?array<mixed>,error:?string}|null,
	 * }
	 */
	public function health(?string $projectId = null): array
	{
		$sections = [
			'team' => $this->read(static fn (Lettermint $client) => $client->team->retrieve()),
			'usage' => $this->read(static fn (Lettermint $client) => $client->team->usage()),
			'project' => null,
			'domains' => null,
		];

		if ($projectId !== null) {
			$sections['project'] = $this->read(static fn (Lettermint $client) => $client->projects->retrieve($projectId, [
				'include' => ['routes', 'domains'],
			]));
		}

		// The domains of a project leave out their status, which only the list of domains has.
		if (null !== $id = self::loadedId($sections['project'])) {
			$sections['domains'] = $this->read(static fn (Lettermint $client) => $client->domains->list([
				'page' => ['size' => self::PAGE_SIZE],
				'sort' => ['domain'],
				'filter' => ['project' => $id],
			]));
		}

		return $sections;
	}

	/**
	 * The ID of a project that could be loaded, or null otherwise.
	 *
	 * @param array{data:?array<mixed>,error:?string}|null $section
	 */
	private static function loadedId(?array $section): ?string
	{
		$id = $section['data']['id'] ?? null;

		return is_string($id) ? $id : null;
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

			$error = self::ERROR_INVALID;
		} catch (PermissionException) {
			$error = self::ERROR_FORBIDDEN;
		} catch (NotFoundException) {
			$error = self::ERROR_MISSING;
		} catch (LettermintException) {
			$error = self::ERROR_UNAVAILABLE;
		}

		return self::error($error);
	}

	/**
	 * @return array{data:null,error:string}
	 */
	private static function error(string $error): array
	{
		return ['data' => null, 'error' => $error];
	}
}
