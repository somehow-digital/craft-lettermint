<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

/**
 * The result of checking a project or team token with Lettermint.
 */
enum TokenStatus: string
{
	/**
	 * Lettermint accepts the token.
	 */
	case Valid = 'valid';

	/**
	 * Lettermint rejects the token, as it is unknown or not allowed from the IP address of the server.
	 */
	case Invalid = 'invalid';

	/**
	 * The token could not be checked, as Lettermint could not be reached or gave an unexpected response.
	 */
	case Unknown = 'unknown';
}
