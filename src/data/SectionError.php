<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\data;

/**
 * Why a section of the team API could not be loaded.
 */
enum SectionError: string
{
	/**
	 * Lettermint rejects the token.
	 */
	case Invalid = 'invalid';

	/**
	 * The token lacks the ability for the section.
	 */
	case Forbidden = 'forbidden';

	/**
	 * The project does not exist.
	 */
	case Missing = 'missing';

	/**
	 * Lettermint could not be read otherwise.
	 */
	case Unavailable = 'unavailable';
}
