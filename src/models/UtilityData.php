<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use DateTimeImmutable;
use SomehowDigital\Craft\Lettermint\enums\ApiError;
use SomehowDigital\Craft\Lettermint\enums\CheckType;
use SomehowDigital\Craft\Lettermint\enums\Setup;

/**
 * The data that the utility and the widget show (Pro edition), as the `utility` component loads and caches it, or
 * placeholder data in the same layout while nothing is cached.
 *
 * The team, its usage, the project and the statistics are only loaded with a team token, and the checks only with
 * Lettermint as the transport.
 *
 * @internal
 */
final readonly class UtilityData
{
	/**
	 * @param Setup|null $setup What is missing before the team can be shown, if anything.
	 * @param Check|null $token The check of the project token, or null without Lettermint as the transport.
	 * @param Check|null $teamToken The check of the team token, or null without a team token.
	 * @param list<Check> $projectChecks The checks of the settings against the project.
	 * @param Team|null $team The team, or null when it was not loaded.
	 * @param ApiError|null $teamError The error the team could not be loaded with, or null without a team token.
	 * @param UsageSummary|null $usage The usage of the current period, or null when it is not known.
	 * @param ApiError|null $usageError The error the usage could not be loaded with.
	 * @param string|null $projectId The ID of the project of the settings.
	 * @param Project|null $project The project, with the status of its domains, or null when it was not loaded.
	 * @param ApiError|null $projectError The error the project could not be loaded with.
	 * @param Stats|null $stats The statistics of the project, or null when they were not loaded.
	 * @param ApiError|null $statsError The error the statistics could not be loaded with.
	 *
	 * The data is only created by `from()`, which keeps the fields consistent with each other, as it is cached for long.
	 */
	private function __construct(
		public ?Setup $setup,
		public ?Check $token,
		public ?Check $teamToken,
		public array $projectChecks,
		public ?Team $team,
		public ?ApiError $teamError,
		public ?UsageSummary $usage,
		public ?ApiError $usageError,
		public ?string $projectId,
		public ?Project $project,
		public ?ApiError $projectError,
		public ?Stats $stats,
		public ?ApiError $statsError,
		public DateTimeImmutable $loadedAt,
	) {}

	/**
	 * The data of the settings, the results of the team API loaded with them and the checks of both.
	 *
	 * @param Settings|null $settings The settings, or null when Lettermint is not the transport.
	 * @param TeamApiData|null $api The results of the team API, or null without a team token.
	 * @param CheckResult|null $checks The checks of the settings, or null when Lettermint is not the transport.
	 */
	public static function from(?Settings $settings, ?TeamApiData $api, ?CheckResult $checks, DateTimeImmutable $loadedAt = new DateTimeImmutable()): self
	{
		return new self(
			setup: match (true) {
				!$settings instanceof Settings => Setup::Transport,
				!$api instanceof TeamApiData => Setup::Token,
				$api->invalid() => Setup::Invalid,
				default => null,
			},
			token: $checks?->token,
			teamToken: $checks?->teamToken,
			projectChecks: $checks->projectChecks ?? [],
			team: $api?->team->data,
			teamError: $api?->team->error,
			usage: $api?->usageSummary(),
			usageError: $api?->usage->error,
			projectId: $settings?->projectId,
			project: $api?->project?->data,
			projectError: $api?->project?->error,
			stats: $api?->stats?->data,
			statsError: $api?->stats?->error,
			loadedAt: $loadedAt,
		);
	}

	/**
	 * Data in the layout that the data of the settings is likely shown in, so the page does not shift once the data is
	 * there.
	 *
	 * @param Settings|null $settings The settings, or null when Lettermint is not the transport.
	 */
	public static function placeholder(?Settings $settings, DateTimeImmutable $now = new DateTimeImmutable()): self
	{
		$ok = static fn(CheckType $type): Check => Check::ok($type, 'Loading from Lettermint…');

		if ($settings?->teamToken === null) {
			return self::from($settings, null, $settings instanceof Settings ? new CheckResult($ok(CheckType::Token)) : null, $now);
		}

		$date = static fn(string $modifier): string => $now->modify($modifier)->format('Y-m-d');
		// The numbers only fill the layout, so any others would do as well.
		$team = new Team(name: 'Team', plan: 'plan', includedVolume: 10000, verifiedAt: $date('-1 year'), createdAt: $date('-1 year')); // @pest-mutate-ignore: IncrementInteger, DecrementInteger
		$usage = new Usage(new Period(used: 1000, start: $date('-15 days'), end: $date('+15 days'))); // @pest-mutate-ignore: IncrementInteger, DecrementInteger
		$project = null;
		$stats = null;

		if ($settings->projectId !== null) {
			$project = ApiResult::loaded(new Project(
				id: $settings->projectId,
				name: 'Project',
				routes: [new Route(slug: 'outgoing', default: true)],
				domains: [new Domain(domain: 'example.com', status: Domain::STATUS_VERIFIED, statusChangedAt: $date('-1 month'))],
			));

			$daily = [];

			for ($day = Stats::DAYS - 1; $day >= 0; --$day) {
				$daily[] = new StatsDay($date("-{$day} days"), 10 + $day % 7 * 5); // @pest-mutate-ignore: IncrementInteger, DecrementInteger
			}

			$stats = ApiResult::loaded(new Stats(
				from: $date(sprintf('-%d days', Stats::DAYS - 1)),
				to: $date('today'),
				totals: new StatsTotals(sent: 1000, delivered: 980, hardBounced: 10, spamComplaints: 1), // @pest-mutate-ignore: IncrementInteger, DecrementInteger
				daily: $daily,
			));
		}

		return self::from($settings, new TeamApiData(ApiResult::loaded($team), ApiResult::loaded($usage), $project, $stats), new CheckResult(
			token: $ok(CheckType::Token),
			teamToken: $ok(CheckType::TeamToken),
			projectChecks: $project instanceof ApiResult ? [$ok(CheckType::Delivery), $ok(CheckType::Sender), $ok(CheckType::Route)] : [],
		), $now);
	}

	/**
	 * Whether the data was loaded from Lettermint, which it is with a team token.
	 */
	public function loaded(): bool
	{
		return $this->team instanceof Team || $this->teamError instanceof ApiError;
	}

	/**
	 * The domains of the project by name, without any when there is no project.
	 *
	 * @return list<Domain>
	 */
	public function domains(): array
	{
		$domains = $this->project->domains ?? [];

		usort($domains, static fn(Domain $a, Domain $b): int => strcmp((string) $a->domain, (string) $b->domain));

		return $domains;
	}
}
