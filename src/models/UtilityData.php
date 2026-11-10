<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use DateTimeImmutable;
use SomehowDigital\Craft\Lettermint\enums\ApiError;
use SomehowDigital\Craft\Lettermint\enums\CheckType;
use SomehowDigital\Craft\Lettermint\enums\Setup;
use SomehowDigital\Craft\Lettermint\enums\Transport;

/**
 * Data shown by the utility and widget (Pro edition), or a placeholder while nothing is cached.
 *
 * Team API data needs a team token; checks need Lettermint as the transport.
 *
 * @internal
 */
final readonly class UtilityData
{
	/**
	 * How long ago the placeholder team and project were created.
	 */
	private const PLACEHOLDER_AGE = '-1 year';

	/**
	 * Private, so `from()` keeps the fields consistent, as the data is cached for long.
	 *
	 * @param Check|null $token Null without Lettermint as the transport.
	 * @param list<Check> $projectChecks
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
	 * @param Settings|null $settings Null when Lettermint is not the transport.
	 * @param TeamApiData|null $api Null without a team token.
	 * @param CheckResult|null $checks Null when Lettermint is not the transport.
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
	 * Matches the likely layout of the real data, so the page doesn't shift once it loads.
	 *
	 * @param Settings|null $settings Null when Lettermint is not the transport.
	 */
	public static function placeholder(?Settings $settings, DateTimeImmutable $now = new DateTimeImmutable()): self
	{
		$ok = static fn(CheckType $type): Check => Check::ok($type, 'Loading from Lettermint…');

		if ($settings?->teamToken === null) {
			return self::from($settings, null, $settings instanceof Settings ? new CheckResult($ok(CheckType::Token)) : null, $now);
		}

		$date = static fn(string $modifier): string => $now->modify($modifier)->format('Y-m-d');
		$team = new Team(name: 'Team', plan: 'plan', includedVolume: 0, verifiedAt: $date(self::PLACEHOLDER_AGE), createdAt: $date(self::PLACEHOLDER_AGE));
		$usage = new Period(used: 0, start: $date('-15 days'), end: $date('+15 days'));
		$project = null;
		$stats = null;

		if ($settings->projectId !== null) {
			$project = ApiResult::loaded(new Project(
				id: $settings->projectId,
				name: 'Project',
				smtpEnabled: false,
				redactEmailContent: false,
				createdAt: $date(self::PLACEHOLDER_AGE),
				routes: [new Route(slug: 'outgoing', type: 'transactional', default: true, trackOpens: false, trackClicks: false)],
				domains: [new Domain(domain: 'example.com', status: Domain::STATUS_VERIFIED, statusChangedAt: $date('-1 month'))],
			));

			$daily = [];

			for ($day = Stats::DAYS - 1; $day >= 0; --$day) {
				$daily[] = new StatsDay($date("-{$day} days"), 0);
			}

			$stats = ApiResult::loaded(new Stats(
				from: $date(sprintf('-%d days', Stats::DAYS - 1)),
				to: $date('today'),
				totals: new StatsTotals(opened: 0, clicked: 0),
				daily: $daily,
			));
		}

		return self::from($settings, new TeamApiData(ApiResult::loaded($team), ApiResult::loaded($usage), $project, $stats), new CheckResult(
			token: $ok(CheckType::Token),
			teamToken: $ok(CheckType::TeamToken),
			projectChecks: $project instanceof ApiResult ? [$ok(CheckType::Delivery), ...($settings->transport === Transport::Smtp ? [$ok(CheckType::Smtp)] : []), $ok(CheckType::Sender), $ok(CheckType::Route)] : [],
		), $now);
	}

	/**
	 * Whether team API data was loaded.
	 */
	public function loaded(): bool
	{
		return $this->team instanceof Team || $this->teamError instanceof ApiError;
	}

	/**
	 * Sorted by name.
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
