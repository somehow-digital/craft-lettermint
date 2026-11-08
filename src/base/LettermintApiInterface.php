<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\base;

use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\models\TeamApiData;

/**
 * Reads the status of the tokens and the data of the team from Lettermint, for the checks and the utility.
 *
 * The plugin binds it to its implementation in the container, so the services do not depend on the SDK of Lettermint.
 *
 * @internal
 */
interface LettermintApiInterface
{
	/**
	 * The status of a project (sending) token, or null when there is no token to check.
	 */
	public function tokenStatus(#[SensitiveParameter] ?string $token): ?TokenStatus;

	/**
	 * The results of the team API: the team, its usage, and the given project with its domains and, with `$withStats`,
	 * its statistics.
	 */
	public function teamApi(#[SensitiveParameter] string $token, ?string $projectId = null, bool $withStats = false): TeamApiData;
}
