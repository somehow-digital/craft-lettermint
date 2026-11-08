<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use DateTimeImmutable;
use Exception;
use SomehowDigital\Craft\Lettermint\enums\ApiError;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;

/**
 * Team API results: team, usage, project and its statistics.
 *
 * `project` is null without a project ID; `stats` whenever not requested.
 *
 * @internal
 */
final readonly class TeamApiData
{
	/**
	 * @param ApiResult<Team> $team
	 * @param ApiResult<Period|null> $usage The current period, if Lettermint sent one.
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
	 * @return list<ApiResult<mixed>>
	 */
	public function requested(): array
	{
		return array_values(array_filter([$this->team, $this->usage, $this->project, $this->stats]));
	}

	/**
	 * Whether any result rejected the token.
	 */
	public function invalid(): bool
	{
		return $this->failedWith(ApiError::Invalid);
	}

	/**
	 * Whether any result failed transiently, so a retry may succeed.
	 */
	public function unavailable(): bool
	{
		return $this->failedWith(ApiError::Unavailable);
	}

	/**
	 * Derived from the results rather than a separate ping. Accepted tokens are valid even if they can't read the team.
	 */
	public function teamTokenStatus(): TokenStatus
	{
		if ($this->invalid()) {
			return TokenStatus::Invalid;
		}

		return in_array($this->team->error, [null, ApiError::Forbidden], true) ? TokenStatus::Valid : TokenStatus::Unknown;
	}

	/**
	 * Null when unknown or the token was rejected.
	 *
	 * Lettermint may report a past period's dates, so they're only kept when the period contains `$now`.
	 */
	public function usageSummary(DateTimeImmutable $now = new DateTimeImmutable()): ?UsageSummary
	{
		if ($this->invalid() || null === $period = $this->usage->data) {
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

	private function contains(?string $from, ?string $to, DateTimeImmutable $time): bool
	{
		$start = $this->date($from);
		$end = $this->date($to);

		if (!$start instanceof DateTimeImmutable) {
			return false;
		}

		// Only narrows the type; `$time < null` is false anyway.
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
