<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\api;

use Lettermint\Exceptions\AuthenticationException;
use Lettermint\Exceptions\LettermintConfigException;
use Lettermint\Exceptions\LettermintException;
use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\base\LettermintApiInterface;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\models\TeamApiData;

/**
 * Checks the tokens and reads the team API of Lettermint with the SDK, for the checks and the utility.
 *
 * @internal
 */
final readonly class LettermintApi implements LettermintApiInterface
{
	/**
	 * The seconds to wait for Lettermint when checking a token, which keeps the pages that show the status from hanging.
	 */
	private const TIMEOUT = 5;

	public function __construct(
		private ClientFactory $clients,
	) {}

	public function tokenStatus(#[SensitiveParameter] ?string $token): ?TokenStatus
	{
		if ($token === null || $token === '') {
			return null;
		}

		try {
			$this->clients->project($token, self::TIMEOUT)->ping();

			return TokenStatus::Valid;
		} catch (AuthenticationException|LettermintConfigException) {
			// The SDK refuses tokens that cannot be sent as a header, such as ones with spaces.
			return TokenStatus::Invalid;
		} catch (LettermintException) {
			return TokenStatus::Unknown;
		}
	}

	public function teamApi(#[SensitiveParameter] string $token, ?string $projectId = null, bool $withStats = false): TeamApiData
	{
		return (new TeamApi($this->clients, $token))->load($projectId, $withStats);
	}
}
