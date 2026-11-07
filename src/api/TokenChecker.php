<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\api;

use Lettermint\Exceptions\AuthenticationException;
use Lettermint\Exceptions\LettermintConfigException;
use Lettermint\Exceptions\LettermintException;
use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;

/**
 * Checks a project (sending) token with Lettermint, for the utility and the health checks.
 *
 * @internal
 */
final class TokenChecker
{
	/**
	 * The seconds to wait for Lettermint, which keeps the pages that show the status from hanging.
	 */
	private const TIMEOUT = 5;

	/**
	 * The status of a token, or null when there is no token to check.
	 */
	public static function check(#[SensitiveParameter] ?string $token): ?TokenStatus
	{
		if ($token === null || $token === '') {
			return null;
		}

		try {
			ClientFactory::project($token, self::TIMEOUT)->ping();

			return TokenStatus::Valid;
		} catch (AuthenticationException|LettermintConfigException) {
			// The SDK refuses tokens that cannot be sent as a header, such as ones with spaces.
			return TokenStatus::Invalid;
		} catch (LettermintException) {
			return TokenStatus::Unknown;
		}
	}
}
