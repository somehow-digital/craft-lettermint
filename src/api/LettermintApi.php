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
 * Checks tokens and reads the team API through the SDK.
 *
 * @internal
 */
final readonly class LettermintApi
{
	/**
	 * Timeout in seconds, so status pages don't hang.
	 */
	private const TIMEOUT = 5;

	/**
	 * @param Closure():ClientInterface $http Creates the HTTP client for each SDK client.
	 */
	public function __construct(
		private Closure $http,
	) {}

	/**
	 * Status of a project (sending) token, or null without one.
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
			// The SDK rejects tokens that aren't valid header values.
			$status = TokenStatus::Invalid;
		} catch (LettermintException) {
			$status = TokenStatus::Unknown;
		}

		return $status;
	}

	public function teamApi(#[SensitiveParameter] string $token, ?string $projectId = null, bool $withStats = false): TeamApiData
	{
		return (new TeamApi(($this->http)(), $token))->load($projectId, $withStats);
	}
}
