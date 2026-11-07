<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use DateTimeImmutable;
use Exception;
use SomehowDigital\Craft\Lettermint\data\Domain;
use SomehowDigital\Craft\Lettermint\data\Project;
use SomehowDigital\Craft\Lettermint\data\Section;
use SomehowDigital\Craft\Lettermint\data\Team;
use SomehowDigital\Craft\Lettermint\data\Usage;

/**
 * Derives the token status, usage and project from the sections of the team API, for the utility, the widget and the
 * checks (Pro edition).
 *
 * @internal
 */
final class Overview
{
	/**
	 * Whether Lettermint rejected the team token for any of the sections.
	 *
	 * @param array<string,?Section<mixed>>|null $sections
	 */
	public static function invalid(?array $sections): bool
	{
		foreach ($sections ?? [] as $section) {
			if ($section?->error === SectionError::Invalid) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The status of the team token, taken from the requests of the sections instead of a ping of its own.
	 *
	 * A token that Lettermint accepts is valid, even when it is not allowed to read the team.
	 *
	 * @param array{team:Section<Team>,...}|null $sections
	 */
	public static function teamTokenStatus(?array $sections): ?TokenStatus
	{
		if ($sections === null) {
			return null;
		}

		if (self::invalid($sections)) {
			return TokenStatus::Invalid;
		}

		return in_array($sections['team']->error, [null, SectionError::Forbidden], true) ? TokenStatus::Valid : TokenStatus::Unknown;
	}

	/**
	 * The usage of the current period, compared with the monthly volume included in the plan, or null when it is not
	 * known or Lettermint rejected the team token.
	 *
	 * The usage of the current period of Lettermint matches its dashboard, though the dates of the period can be ones
	 * that have passed, so they are only given when they contain the time, which is now unless given.
	 *
	 * @param array{team:Section<Team>,usage:Section<Usage>,...}|null $sections
	 * @return array{used:int,included:?int,share:?float,start:?string,end:?string}|null
	 */
	public static function usage(?array $sections, DateTimeImmutable $now = new DateTimeImmutable()): ?array
	{
		if ($sections === null || self::invalid($sections) || null === $period = $sections['usage']->data?->current) {
			return null;
		}

		$included = $sections['team']->data?->includedVolume;

		$dated = self::contains($period->start, $period->end, $now);

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
	 * @param array{project:?Section<Project>,domains:?Section<list<Domain>>,...} $sections
	 * @return array{id:?string,data:?Project,error:?SectionError,domains:list<Domain>}
	 */
	public static function project(?string $projectId, array $sections): array
	{
		$project = self::projectData($sections);
		$domains = $project->domains ?? [];

		usort($domains, static fn(Domain $a, Domain $b): int => strcmp((string) $a->domain, (string) $b->domain));

		return [
			'id' => $projectId,
			'data' => $project,
			'error' => $sections['project']?->error,
			'domains' => $domains,
		];
	}

	/**
	 * The project of the token, with the status of its listed domains, or null when it was not loaded.
	 *
	 * @param array{project:?Section<Project>,domains:?Section<list<Domain>>,...} $sections
	 */
	public static function projectData(array $sections): ?Project
	{
		return $sections['project']?->data?->withStatuses($sections['domains']->data ?? []);
	}

	/**
	 * Whether the period between the dates contains the time.
	 */
	private static function contains(?string $from, ?string $to, DateTimeImmutable $time): bool
	{
		$start = self::date($from);
		$end = self::date($to);

		if (!$start instanceof DateTimeImmutable) {
			return false;
		}

		// No time is before a missing end, so the period contains none, though its type needs the check.
		if (!$end instanceof DateTimeImmutable) { // @pest-mutate-ignore: InstanceOfToTrue
			return false; // @pest-mutate-ignore: RemoveEarlyReturn
		}

		return $start <= $time && $time < $end;
	}

	private static function date(?string $value): ?DateTimeImmutable
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
