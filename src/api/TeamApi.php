<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\api;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\ClientInterface;
use Lettermint\Exceptions\AuthenticationException;
use Lettermint\Exceptions\LettermintConfigException;
use Lettermint\Exceptions\LettermintException;
use Lettermint\Exceptions\NotFoundException;
use Lettermint\Exceptions\PermissionException;
use Lettermint\Lettermint;
use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\enums\ApiError;
use SomehowDigital\Craft\Lettermint\models\ApiResult;
use SomehowDigital\Craft\Lettermint\models\Period;
use SomehowDigital\Craft\Lettermint\models\Project;
use SomehowDigital\Craft\Lettermint\models\Stats;
use SomehowDigital\Craft\Lettermint\models\Team;
use SomehowDigital\Craft\Lettermint\models\TeamApiData;

/**
 * Reads the team, its usage, and the project with its domains and statistics from the team API of Lettermint, once for
 * each instance, which skips the remaining requests once Lettermint rejects the token.
 *
 * @internal
 */
final class TeamApi
{
	/**
	 * The most domains of the project that are listed.
	 */
	public const PAGE_SIZE = 100;

	/**
	 * The seconds to wait for Lettermint, which keeps the pages from hanging.
	 */
	private const TIMEOUT = 10;

	/**
	 * Whether Lettermint rejected the token, which skips the remaining requests.
	 */
	private bool $rejected = false;

	public function __construct(
		private readonly ClientInterface $http,
		#[SensitiveParameter]
		private readonly string $token,
	) {}

	/**
	 * Requests the results of the team and the given project.
	 *
	 * Only the team, its usage and the given project are requested, never the other projects of the team. The
	 * `project` result holds the given project with the status of its domains, and the `stats` result its statistics,
	 * which are null without a project, or when it could not be loaded. The `stats` result is only requested with
	 * `$withStats`.
	 */
	public function load(?string $projectId = null, bool $withStats = false): TeamApiData
	{
		$team = $this->read(static fn(Lettermint $client): Team => Mapper::team($client->team->retrieve()));
		$usage = $this->read(static fn(Lettermint $client): ?Period => Mapper::usage($client->team->usage()));
		$project = $projectId !== null ? $this->project($projectId) : null;
		$stats = null;

		if ($withStats && null !== $id = $project?->data?->id) {
			$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

			$stats = $this->read(static fn(Lettermint $client): Stats => Mapper::stats($client->stats->retrieve([
				'from' => $today->modify(sprintf('-%d days', Stats::DAYS - 1))->format('Y-m-d'),
				'to' => $today->format('Y-m-d'),
				'project_id' => $id,
			])));
		}

		return new TeamApiData($team, $usage, $project, $stats);
	}

	/**
	 * Requests a project, with the status of its domains taken from the list of domains, as the domains of a project
	 * leave it out.
	 *
	 * Without the list, the status of the domains is not known. Once Lettermint rejects the token for the list, the
	 * project is rejected as well, as nothing that was loaded with the token is shown then.
	 *
	 * @return ApiResult<Project>
	 */
	private function project(string $projectId): ApiResult
	{
		$project = $this->read(static fn(Lettermint $client): Project => Mapper::project($client->projects->retrieve($projectId, [
			'include' => ['routes', 'domains'],
		])));

		$data = $project->data;

		if (!$data instanceof Project || null === $id = $data->id) {
			return $project;
		}

		$listed = $this->read(static fn(Lettermint $client): array => Mapper::domains($client->domains->list([
			'page' => ['size' => self::PAGE_SIZE],
			'sort' => ['domain'],
			'filter' => ['project' => $id],
		])->get('data')));

		return $listed->error === ApiError::Invalid
			? ApiResult::failed(ApiError::Invalid)
			: ApiResult::loaded($data->withStatuses($listed->data ?? []));
	}

	/**
	 * Reads a result with the client.
	 *
	 * @template T
	 * @param callable(Lettermint):T $request
	 * @return ApiResult<T>
	 */
	private function read(callable $request): ApiResult
	{
		if ($this->rejected) {
			return ApiResult::failed(ApiError::Invalid);
		}

		try {
			return ApiResult::loaded($request(new Lettermint(teamToken: $this->token, timeout: self::TIMEOUT, httpClient: $this->http)));
		} catch (AuthenticationException|LettermintConfigException) {
			// The SDK refuses tokens that cannot be sent as a header, such as ones with spaces.
			$this->rejected = true;

			$error = ApiError::Invalid;
		} catch (PermissionException) {
			$error = ApiError::Forbidden;
		} catch (NotFoundException) {
			$error = ApiError::Missing;
		} catch (LettermintException) {
			$error = ApiError::Unavailable;
		}

		return ApiResult::failed($error);
	}
}
