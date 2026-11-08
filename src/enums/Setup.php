<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\enums;

/**
 * What's missing before the utility and widget can show the team.
 *
 * @internal
 */
enum Setup: string
{
	/**
	 * Lettermint is not the email transport.
	 */
	case Transport = 'transport';

	/**
	 * The team API token is not set.
	 */
	case Token = 'token';

	/**
	 * Lettermint rejects the team API token.
	 */
	case Invalid = 'invalid';
}
