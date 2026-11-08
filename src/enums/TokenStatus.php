<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\enums;

/**
 * Result of checking a project or team token.
 *
 * @internal
 */
enum TokenStatus: string
{
	/**
	 * Lettermint accepts the token.
	 */
	case Valid = 'valid';

	/**
	 * Lettermint rejects the token: unknown, or not allowed from the server's IP.
	 */
	case Invalid = 'invalid';

	/**
	 * Lettermint was unreachable or responded unexpectedly.
	 */
	case Unknown = 'unknown';
}
