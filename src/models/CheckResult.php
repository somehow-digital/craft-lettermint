<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * The checks built by `Checks::build()`, kept apart so the utility and widget can place each one.
 *
 * @internal
 */
final readonly class CheckResult
{
	/**
	 * @param Check|null $teamToken Null without a team token.
	 * @param Check|null $usage Null when unknown.
	 * @param Check|null $project Only set when the project failed to load.
	 * @param list<Check> $projectChecks The settings checked against the project.
	 */
	public function __construct(
		public Check $token,
		public ?Check $teamToken = null,
		public ?Check $usage = null,
		public ?Check $project = null,
		public array $projectChecks = [],
	) {}

	/**
	 * In the order the health endpoint reports them.
	 *
	 * @return list<Check>
	 */
	public function all(): array
	{
		return [...array_filter([$this->token, $this->teamToken, $this->usage, $this->project]), ...$this->projectChecks];
	}
}
