<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

/**
 * The checks of the tokens, the usage and the project, as `Checks::result()` builds them, for the health endpoint to
 * list them all and the utility and the widget to show each where it belongs.
 *
 * Without a team token, only the project token is checked, and once Lettermint rejects the team token, nothing after
 * it, as nothing more could be loaded then.
 */
final readonly class CheckResult
{
	/**
	 * @param Check $token The check of the project token.
	 * @param Check|null $teamToken The check of the team token, or null without a team token.
	 * @param Check|null $usage The check of the usage, or null when it is not known.
	 * @param Check|null $project The check of a project that could not be loaded, or null when it was.
	 * @param list<Check> $projectChecks The checks of the settings against the project, without any when there is none.
	 */
	public function __construct(
		public Check $token,
		public ?Check $teamToken = null,
		public ?Check $usage = null,
		public ?Check $project = null,
		public array $projectChecks = [],
	) {}

	/**
	 * All checks, in the order the health endpoint reports them.
	 *
	 * @return list<Check>
	 */
	public function all(): array
	{
		return [...array_filter([$this->token, $this->teamToken, $this->usage, $this->project]), ...$this->projectChecks];
	}
}
