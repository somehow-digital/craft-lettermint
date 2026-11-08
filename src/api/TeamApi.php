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
 * Reads the team, usage, project and stats from the team API, skipping further requests once the token is rejected.
 *
 * @internal
 */
final class TeamApi
{
	/**
	 * Maximum number of domains listed.
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Timeout in seconds, so pages don't hang.
	 */
	private const TIMEOUT = 10;

	private bool $rejected = false;

	public function __construct(
		private readonly ClientInterface $http,
		#[SensitiveParameter]
		private readonly string $token,
	) {}

	/**
	 * Never requests the team's other projects. `project` and `stats` are null without a project; `stats` also when
	 * the project failed to load or `$withStats` is false.
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
	 * Takes domain statuses from the domain list, as a project's domains lack them. A rejected list rejects the
	 * project too.
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
			// The SDK rejects tokens that aren't valid header values.
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
