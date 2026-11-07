<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use DateTimeImmutable;
use Exception;
use SomehowDigital\Craft\Lettermint\enums\ApiError;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;

/**
 * The results of the team API: the team, its usage, and the project of the token, with the status of its domains, and
 * its statistics.
 *
 * The project is null without a project, and its statistics when they were not requested: without a project, or when
 * the project could not be loaded. The health checks leave out the statistics as well.
 *
 * @internal
 */
final readonly class TeamApiData
{
	/**
	 * @param ApiResult<Team> $team
	 * @param ApiResult<Usage> $usage
	 * @param ApiResult<Project>|null $project
	 * @param ApiResult<Stats>|null $stats
	 */
	public function __construct(
		public ApiResult $team,
		public ApiResult $usage,
		public ?ApiResult $project = null,
		public ?ApiResult $stats = null,
	) {}

	/**
	 * The results that were requested.
	 *
	 * @return list<ApiResult<mixed>>
	 */
	public function requested(): array
	{
		return array_values(array_filter([$this->team, $this->usage, $this->project, $this->stats]));
	}

	/**
	 * Whether Lettermint rejected the team token for any of the results.
	 */
	public function invalid(): bool
	{
		return $this->failedWith(ApiError::Invalid);
	}

	/**
	 * Whether Lettermint could not be read for any of the results, so they may be loaded with another request.
	 */
	public function unavailable(): bool
	{
		return $this->failedWith(ApiError::Unavailable);
	}

	/**
	 * The status of the team token, taken from the requests of the results instead of a ping of its own.
	 *
	 * A token that Lettermint accepts is valid, even when it is not allowed to read the team.
	 */
	public function teamTokenStatus(): TokenStatus
	{
		if ($this->invalid()) {
			return TokenStatus::Invalid;
		}

		return in_array($this->team->error, [null, ApiError::Forbidden], true) ? TokenStatus::Valid : TokenStatus::Unknown;
	}

	/**
	 * The usage of the current period, compared with the monthly volume included in the plan, or null when it is not
	 * known or Lettermint rejected the team token.
	 *
	 * The usage of the current period of Lettermint matches its dashboard, though the dates of the period can be ones
	 * that have passed, so they are only given when they contain the time, which is now unless given.
	 */
	public function usageSummary(DateTimeImmutable $now = new DateTimeImmutable()): ?UsageSummary
	{
		if ($this->invalid() || null === $period = $this->usage->data?->current) {
			return null;
		}

		$dated = $this->contains($period->start, $period->end, $now);

		return new UsageSummary(
			used: $period->used,
			included: $this->team->data?->includedVolume,
			start: $dated ? $period->start : null,
			end: $dated ? $period->end : null,
		);
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

	/**
	 * Whether any of the results could not be loaded with the error.
	 */
	private function failedWith(ApiError $error): bool
	{
		foreach ($this->requested() as $result) {
			if ($result->error === $error) {
				return true;
			}
		}

		return false;
	}
}
