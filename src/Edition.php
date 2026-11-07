<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

/**
 * The editions of the plugin, from the lowest to the highest.
 */
enum Edition: string
{
	case Standard = 'standard';
	case Pro = 'pro';
}
