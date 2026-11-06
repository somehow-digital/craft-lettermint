<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use DateTimeImmutable;
use Exception;

/**
 * Derives the token status, usage and project from the sections of the team API, for the utility and the health
 * endpoint (Pro edition).
 *
 * @internal
 * @phpstan-import-type Section from TeamApi
 */
final class Overview
{
	/**
	 * Whether Lettermint rejected the team token for any of the sections.
	 *
	 * @param array<string,?Section>|null $sections
	 */
	public static function invalid(?array $sections): bool
	{
		return $sections !== null && in_array(TeamApi::ERROR_INVALID, array_column($sections, 'error'), true);
	}

	/**
	 * The status of the team token, taken from the requests of the sections instead of a ping of its own.
	 *
	 * A token that Lettermint accepts is valid, even when it is not allowed to read the team.
	 *
	 * @param array{team:Section,...}|null $sections
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
	 * that have passed, so they are only given when they contain the time, which is now unless given.
	 *
	 * @param array{team:Section,usage:Section,...}|null $sections
	 * @return array{used:int,included:?int,share:?float,start:?string,end:?string}|null
	 */
	public static function usage(?array $sections, DateTimeImmutable $now = new DateTimeImmutable()): ?array
	{
		$period = $sections['usage']['data']['current_period'] ?? null;

		if (!is_array($period)) {
			return null;
		}

		$used = $period['usage'] ?? null;
		$used = is_numeric($used) ? (int) $used : 0;

		$included = $sections['team']['data']['included_volume'] ?? null;
		$included = is_numeric($included) && (int) $included > 0 ? (int) $included : null;

		$start = Env::nonEmpty($period['period_start'] ?? null);
		$end = Env::nonEmpty($period['period_end'] ?? null);
		$dated = self::contains($start, $end, $now);

		return [
			'used' => $used,
			'included' => $included,
			'share' => $included !== null ? (float) ($used / $included) : null,
			'start' => $dated ? $start : null,
			'end' => $dated ? $end : null,
		];
	}

	/**
	 * The project of the token, with checks of the settings against it.
	 *
	 * @param array{project:?Section,domains:?Section,...} $sections
	 * @return array{id:?string,data:?array<mixed>,error:?string,domains:list<array<mixed>>,checks:list<array{id:string,status:string,text:string}>}
	 */
	public static function project(Adapter $adapter, ?string $projectId, array $sections): array
	{
		$data = $sections['project']['data'] ?? null;
		$domains = $sections['domains']['data']['data'] ?? null;
		$domains = is_array($domains) ? $domains : [];

		return [
			'id' => $projectId,
			'data' => $data,
			'error' => $sections['project']['error'] ?? null,
			'domains' => is_array($data) ? self::projectDomains($data, $domains) : [],
			'checks' => is_array($data)
				? (new ProjectChecks($adapter, $data, $domains))->all()
				: [],
		];
	}

	/**
	 * The domains of the project, with the status of the listed domains, which the domains of a project leave out.
	 *
	 * @param array<mixed> $project
	 * @param array<mixed> $listed
	 * @return list<array<mixed>>
	 */
	private static function projectDomains(array $project, array $listed): array
	{
		$statuses = self::byId($listed);
		$domains = [];

		foreach (is_array($project['domains'] ?? null) ? $project['domains'] : [] as $domain) {
			if (is_array($domain)) {
				$id = $domain['id'] ?? null;
				$domains[] = array_merge(is_string($id) ? $statuses[$id] ?? [] : [], $domain);
			}
		}

		usort($domains, static fn(array $a, array $b): int => strcmp(self::name($a), self::name($b)));

		return $domains;
	}

	/**
	 * The domains keyed by their ID, leaving out those without one.
	 *
	 * @param array<mixed> $domains
	 * @return array<string,array<mixed>>
	 */
	private static function byId(array $domains): array
	{
		$keyed = [];

		foreach ($domains as $domain) {
			if (is_array($domain) && is_string($domain['id'] ?? null)) {
				$keyed[$domain['id']] = $domain;
			}
		}

		return $keyed;
	}

	/**
	 * The name of a domain, for sorting.
	 *
	 * @param array<mixed> $domain
	 */
	private static function name(array $domain): string
	{
		return is_string($domain['domain'] ?? null) ? $domain['domain'] : '';
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
