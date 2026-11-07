<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use DateTimeImmutable;
use SomehowDigital\Craft\Lettermint\data\Domain;
use SomehowDigital\Craft\Lettermint\data\Project;
use SomehowDigital\Craft\Lettermint\data\Section;
use SomehowDigital\Craft\Lettermint\data\SectionError;
use SomehowDigital\Craft\Lettermint\data\Sections;
use SomehowDigital\Craft\Lettermint\data\Stats;
use SomehowDigital\Craft\Lettermint\data\Team;
use SomehowDigital\Craft\Lettermint\data\UsageSummary;

/**
 * The data that the utility and the widget show (Pro edition), as the `utility` component loads and caches it.
 *
 * The team, its usage, the project and the statistics are only loaded with a team token, and the checks only with
 * Lettermint as the transport.
 */
final readonly class UtilityData
{
	/**
	 * @param Setup|null $setup What is missing before the team can be shown, if anything.
	 * @param Check|null $token The check of the project token, or null without Lettermint as the transport.
	 * @param Check|null $teamToken The check of the team token, or null without a team token.
	 * @param list<Check> $projectChecks The checks of the settings against the project.
	 * @param Section<Team>|null $team The team, or null without a team token.
	 * @param UsageSummary|null $usage The usage of the current period, or null when it is not known.
	 * @param SectionError|null $usageError The error the usage could not be loaded with.
	 * @param string|null $projectId The ID of the project of the settings.
	 * @param Project|null $project The project, with the status of its domains, or null when it was not loaded.
	 * @param SectionError|null $projectError The error the project could not be loaded with.
	 * @param Section<Stats>|null $stats The statistics of the project, or null when they were not requested.
	 */
	public function __construct(
		public ?Setup $setup,
		public ?Check $token,
		public ?Check $teamToken,
		public array $projectChecks,
		public ?Section $team,
		public ?UsageSummary $usage,
		public ?SectionError $usageError,
		public ?string $projectId,
		public ?Project $project,
		public ?SectionError $projectError,
		public ?Section $stats,
		public DateTimeImmutable $loadedAt,
	) {}

	/**
	 * The data of the settings, the sections of the team API loaded with them and the checks of both.
	 *
	 * @param Settings|null $settings The settings, or null when Lettermint is not the transport.
	 * @param Sections|null $sections The sections of the team API, or null without a team token.
	 * @param Checks|null $checks The checks of the settings, or null when Lettermint is not the transport.
	 */
	public static function from(?Settings $settings, ?Sections $sections, ?Checks $checks, DateTimeImmutable $loadedAt = new DateTimeImmutable()): self
	{
		return new self(
			setup: match (true) {
				!$settings instanceof Settings => Setup::Transport,
				!$sections instanceof Sections => Setup::Token,
				$sections->invalid() => Setup::Invalid,
				default => null,
			},
			token: $checks?->token(),
			teamToken: $checks?->teamToken(),
			projectChecks: $checks?->project() ?? [],
			team: $sections?->team,
			usage: $sections?->usageSummary(),
			usageError: $sections?->usage->error,
			projectId: $settings?->projectId,
			project: $sections?->projectData(),
			projectError: $sections?->project?->error,
			stats: $sections?->stats,
			loadedAt: $loadedAt,
		);
	}

	/**
	 * Whether the data was loaded from Lettermint, which it is with a team token.
	 */
	public function loaded(): bool
	{
		return $this->team instanceof Section;
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
