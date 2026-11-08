<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\enums;

/**
 * Why a team API result failed to load.
 *
 * @internal
 */
enum ApiError: string
{
	/**
	 * Lettermint rejects the token.
	 */
	case Invalid = 'invalid';

	/**
	 * The token lacks the required ability.
	 */
	case Forbidden = 'forbidden';

	/**
	 * The project does not exist.
	 */
	case Missing = 'missing';

	/**
	 * Any other failure.
	 */
	case Unavailable = 'unavailable';
}
