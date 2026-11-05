<?php

namespace SomehowDigital\Craft\Lettermint;

use DateTimeImmutable;

/**
 * Derives the token status, usage and project from the sections of the team API, for the utility and the health
 * endpoint (Pro edition).
 *
 * @internal
 */
final class Overview
{
	/**
	 * Whether Lettermint rejected the team token for any of the sections.
	 *
	 * @param array<string,mixed>|null $sections
	 */
	public static function invalid(?array $sections): bool
	{
		return $sections !== null && in_array(TeamApi::ERROR_INVALID, array_column(array_filter($sections), 'error'), true);
	}

	/**
	 * The status of the team token, taken from the requests of the sections instead of a ping of its own.
	 *
	 * A token that Lettermint accepts is valid, even when it is not allowed to read the team.
	 *
	 * @param array<string,mixed>|null $sections
	 */
	public static function teamTokenStatus(?array $sections): ?TokenStatus
	{
		if ($sections === null) {
			return null;
		}

		if (self::invalid($sections)) {
			return TokenStatus::Invalid;
		}

		return in_array($sections['team']['error'], [null, TeamApi::ERROR_FORBIDDEN], true) ? TokenStatus::Valid : TokenStatus::Unknown;
	}

	/**
	 * The usage of the current period, compared with the monthly volume included in the plan.
	 *
	 * The usage of the current period of Lettermint matches its dashboard, though the dates of the period can be ones
	 * that have passed, so they are only given when they contain the current time.
	 *
	 * @param array<string,mixed>|null $sections
	 * @return array{used:int,included:?int,share:?float,start:?string,end:?string}|null
	 */
	public static function usage(?array $sections): ?array
	{
		$period = $sections['usage']['data']['current_period'] ?? null;

		if (!is_array($period)) {
			return null;
		}

		$used = (int) ($period['usage'] ?? 0);
		$included = $sections['team']['data']['included_volume'] ?? null;
		$included = is_numeric($included) && $included > 0 ? (int) $included : null;

		$now = new DateTimeImmutable();
		$start = self::date($period['period_start'] ?? null);
		$end = self::date($period['period_end'] ?? null);
		$dated = $start !== null && $end !== null && $start <= $now && $now < $end;

		return [
			'used' => $used,
			'included' => $included,
			'share' => $included !== null ? $used / $included : null,
			'start' => $dated ? $period['period_start'] : null,
			'end' => $dated ? $period['period_end'] : null,
		];
	}

	/**
	 * The project of the token, with checks of the settings against it.
	 *
	 * @param array<string,mixed> $sections
	 * @return array{id:?string,auto:bool,data:?array<mixed>,error:?string,checks:list<array{id:string,status:string,text:string}>}
	 */
	public static function project(Adapter $adapter, ?string $projectId, array $sections): array
	{
		$section = $sections['project'] ?? null;
		$data = $section['data'] ?? null;

		// The project details only have their statistics when they are included, which the list of projects always has.
		if (is_array($data)) {
			foreach ($sections['projects']['data']['data'] ?? [] as $listed) {
				if (($listed['id'] ?? null) === ($data['id'] ?? false) && is_array($listed['last_28_days'] ?? null)) {
					$data['last_28_days'] = $listed['last_28_days'];
					break;
				}
			}
		}

		return [
			'id' => $projectId,
			'auto' => $projectId === null && $section !== null,
			'data' => $data,
			'error' => $section['error'] ?? null,
			'checks' => is_array($data)
				? (new ProjectChecks($adapter, $data, $sections['domains']['data']['data'] ?? []))->all()
				: [],
		];
	}

	private static function date(mixed $value): ?DateTimeImmutable
	{
		if (!is_string($value) || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}
	}
}
