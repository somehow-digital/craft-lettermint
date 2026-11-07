<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

/**
 * What is missing before the utility and the widget can show the team.
 */
enum Setup: string
{
	/**
	 * Lettermint is not the transport of the email settings.
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
