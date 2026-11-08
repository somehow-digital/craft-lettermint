<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\api;

use Closure;
use GuzzleHttp\ClientInterface;
use Lettermint\Exceptions\AuthenticationException;
use Lettermint\Exceptions\LettermintConfigException;
use Lettermint\Exceptions\LettermintException;
use Lettermint\Lettermint;
use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\models\TeamApiData;

/**
 * Checks the tokens and reads the team API of Lettermint with the SDK, for the checks and the utility.
 *
 * The plugin creates it in the container with the HTTP client of Craft, which projects can replace there.
 *
 * @internal
 */
final readonly class LettermintApi
{
	/**
	 * The seconds to wait for Lettermint when checking a token, which keeps the pages that show the status from hanging.
	 */
	private const TIMEOUT = 5;

	/**
	 * @param Closure():ClientInterface $http Creates the HTTP client of each client of the SDK.
	 */
	public function __construct(
		private Closure $http,
	) {}

	/**
	 * The status of a project (sending) token, or null when there is no token to check.
	 */
	public function tokenStatus(#[SensitiveParameter] ?string $token): ?TokenStatus
	{
		if ($token === null || $token === '') {
			return null;
		}

		try {
			(new Lettermint(sendingToken: $token, timeout: self::TIMEOUT, httpClient: ($this->http)()))->ping();

			$status = TokenStatus::Valid;
		} catch (AuthenticationException|LettermintConfigException) {
			// The SDK refuses tokens that cannot be sent as a header, such as ones with spaces.
			$status = TokenStatus::Invalid;
		} catch (LettermintException) {
			$status = TokenStatus::Unknown;
		}

		return $status;
	}

	/**
	 * The results of the team API: the team, its usage, and the given project with its domains and, with `$withStats`,
	 * its statistics.
	 */
	public function teamApi(#[SensitiveParameter] string $token, ?string $projectId = null, bool $withStats = false): TeamApiData
	{
		return (new TeamApi(($this->http)(), $token))->load($projectId, $withStats);
	}
}
