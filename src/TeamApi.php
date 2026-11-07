<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use DateTimeImmutable;
use DateTimeZone;
use Lettermint\Exceptions\AuthenticationException;
use Lettermint\Exceptions\LettermintConfigException;
use Lettermint\Exceptions\LettermintException;
use Lettermint\Exceptions\NotFoundException;
use Lettermint\Exceptions\PermissionException;
use Lettermint\Lettermint;
use Lettermint\Types\DomainListData;
use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\data\Domain;
use SomehowDigital\Craft\Lettermint\data\Field;
use SomehowDigital\Craft\Lettermint\data\Project;
use SomehowDigital\Craft\Lettermint\data\Section;
use SomehowDigital\Craft\Lettermint\data\SectionError;
use SomehowDigital\Craft\Lettermint\data\Sections;
use SomehowDigital\Craft\Lettermint\data\Stats;
use SomehowDigital\Craft\Lettermint\data\Team;
use SomehowDigital\Craft\Lettermint\data\Usage;

/**
 * Reads the team, its usage, and the project with its domains and statistics from the team API of Lettermint.
 */
class TeamApi
{
	/**
	 * The days of message statistics, up to and including today.
	 */
	public const STATS_DAYS = 28;

	/**
	 * The most domains of the project that are listed.
	 */
	public const PAGE_SIZE = 100;

	/**
	 * The seconds to wait for Lettermint, which keeps the pages from hanging.
	 */
	private const TIMEOUT = 10;

	/**
	 * The client of the SDK, created with the first request.
	 */
	private ?Lettermint $client = null;

	/**
	 * Whether Lettermint rejected the token, which skips the remaining requests.
	 */
	private bool $rejected = false;

	public function __construct(
		#[SensitiveParameter]
		private readonly string $token,
	) {}

	/**
	 * Requests all sections.
	 *
	 * Only the team, its usage and the given project are requested, never the other projects of the team. The
	 * `project` section holds the given project, and the `domains` and `stats` sections its domains and statistics,
	 * which are null without a project, or when it could not be loaded.
	 */
	public function overview(?string $projectId = null): Sections
	{
		$sections = $this->health($projectId);

		if (null === $id = $sections->project?->data?->id) {
			return $sections;
		}

		$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

		return new Sections(
			team: $sections->team,
			usage: $sections->usage,
			project: $sections->project,
			domains: $sections->domains,
			stats: $this->read(static fn(Lettermint $client): Stats => Stats::fromApi($client->stats->retrieve([
				'from' => $today->modify(sprintf('-%d days', self::STATS_DAYS - 1))->format('Y-m-d'),
				'to' => $today->format('Y-m-d'),
				'project_id' => $id,
			]))),
		);
	}

	/**
	 * Requests only the sections that the health checks need, those of `overview()` without the statistics.
	 */
	public function health(?string $projectId = null): Sections
	{
		$team = $this->read(static fn(Lettermint $client): Team => Team::fromApi($client->team->retrieve()));
		$usage = $this->read(static fn(Lettermint $client): Usage => Usage::fromApi($client->team->usage()));
		$project = null;
		$domains = null;

		if ($projectId !== null) {
			$project = $this->read(static fn(Lettermint $client): Project => Project::fromApi($client->projects->retrieve($projectId, [
				'include' => ['routes', 'domains'],
			])));
		}

		// The domains of a project leave out their status, which only the list of domains has.
		if (null !== $id = $project?->data?->id) {
			$domains = $this->read(static fn(Lettermint $client): array => array_map(
				Domain::fromApi(...),
				Field::list($client->domains->list([
					'page' => ['size' => self::PAGE_SIZE],
					'sort' => ['domain'],
					'filter' => ['project' => $id],
				])->get('data'), DomainListData::class),
			));
		}

		return new Sections($team, $usage, $project, $domains);
	}

	/**
	 * Reads a section with the client.
	 *
	 * @template T
	 * @param callable(Lettermint):T $request
	 * @return Section<T>
	 */
	private function read(callable $request): Section
	{
		if ($this->rejected) {
			return Section::failed(SectionError::Invalid);
		}

		try {
			$this->client ??= ClientFactory::team($this->token, self::TIMEOUT);

			return Section::loaded($request($this->client));
		} catch (AuthenticationException|LettermintConfigException) {
			// The SDK refuses tokens that cannot be sent as a header, such as ones with spaces.
			$this->rejected = true;

			$error = SectionError::Invalid;
		} catch (PermissionException) {
			$error = SectionError::Forbidden;
		} catch (NotFoundException) {
			$error = SectionError::Missing;
		} catch (LettermintException) {
			$error = SectionError::Unavailable;
		}

		return Section::failed($error);
	}
}
