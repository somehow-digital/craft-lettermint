<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\data;

use DateTimeImmutable;
use Exception;
use SomehowDigital\Craft\Lettermint\SectionError;
use SomehowDigital\Craft\Lettermint\TokenStatus;

/**
 * The sections of the team API: the team, its usage, and the project of the token with its domains and statistics.
 *
 * The project, its domains and its statistics are null when they were not requested: without a project, or when the
 * project could not be loaded. The health checks leave out the statistics as well.
 */
final readonly class Sections
{
	/**
	 * @param Section<Team> $team
	 * @param Section<Usage> $usage
	 * @param Section<Project>|null $project
	 * @param Section<list<Domain>>|null $domains
	 * @param Section<Stats>|null $stats
	 */
	public function __construct(
		public Section $team,
		public Section $usage,
		public ?Section $project = null,
		public ?Section $domains = null,
		public ?Section $stats = null,
	) {}

	/**
	 * The sections that were requested.
	 *
	 * @return list<Section<mixed>>
	 */
	public function requested(): array
	{
		return array_values(array_filter([$this->team, $this->usage, $this->project, $this->domains, $this->stats]));
	}

	/**
	 * Whether Lettermint rejected the team token for any of the sections.
	 */
	public function invalid(): bool
	{
		foreach ($this->requested() as $section) {
			if ($section->error === SectionError::Invalid) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The status of the team token, taken from the requests of the sections instead of a ping of its own.
	 *
	 * A token that Lettermint accepts is valid, even when it is not allowed to read the team.
	 */
	public function teamTokenStatus(): TokenStatus
	{
		if ($this->invalid()) {
			return TokenStatus::Invalid;
		}

		return in_array($this->team->error, [null, SectionError::Forbidden], true) ? TokenStatus::Valid : TokenStatus::Unknown;
	}

	/**
	 * The usage of the current period, compared with the monthly volume included in the plan, or null when it is not
	 * known or Lettermint rejected the team token.
	 *
	 * The usage of the current period of Lettermint matches its dashboard, though the dates of the period can be ones
	 * that have passed, so they are only given when they contain the time, which is now unless given.
	 *
	 * @return array{used:int,included:?int,share:?float,start:?string,end:?string}|null
	 */
	public function usage(DateTimeImmutable $now = new DateTimeImmutable()): ?array
	{
		if ($this->invalid() || null === $period = $this->usage->data?->current) {
			return null;
		}

		$included = $this->team->data?->includedVolume;

		$dated = $this->contains($period->start, $period->end, $now);

		return [
			'used' => $period->used,
			'included' => $included,
			'share' => $included !== null ? (float) ($period->used / $included) : null,
			'start' => $dated ? $period->start : null,
			'end' => $dated ? $period->end : null,
		];
	}

	/**
	 * The project of the token, with its domains by name.
	 *
	 * @return array{id:?string,data:?Project,error:?SectionError,domains:list<Domain>}
	 */
	public function project(?string $projectId): array
	{
		$project = $this->projectData();
		$domains = $project->domains ?? [];

		usort($domains, static fn(Domain $a, Domain $b): int => strcmp((string) $a->domain, (string) $b->domain));

		return [
			'id' => $projectId,
			'data' => $project,
			'error' => $this->project?->error,
			'domains' => $domains,
		];
	}

	/**
	 * The project of the token, with the status of its listed domains, or null when it was not loaded.
	 */
	public function projectData(): ?Project
	{
		return $this->project?->data?->withStatuses($this->domains->data ?? []);
	}

	/**
	 * Whether the period between the dates contains the time.
	 */
	private function contains(?string $from, ?string $to, DateTimeImmutable $time): bool
	{
		$start = $this->date($from);
		$end = $this->date($to);

		if (!$start instanceof DateTimeImmutable) {
			return false;
		}

		// No time is before a missing end, so the period contains none, though its type needs the check.
		if (!$end instanceof DateTimeImmutable) { // @pest-mutate-ignore: InstanceOfToTrue
			return false; // @pest-mutate-ignore: RemoveEarlyReturn
		}

		return $start <= $time && $time < $end;
	}

	private function date(?string $value): ?DateTimeImmutable
	{
		if ($value === null) {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception) {
			return null;
		}
	}
}
