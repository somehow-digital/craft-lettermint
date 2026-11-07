<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\enums;

/**
 * The editions of the plugin, from the lowest to the highest.
 *
 * @internal
 */
enum Edition: string
{
	case Standard = 'standard';
	case Pro = 'pro';
}
