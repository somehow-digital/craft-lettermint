<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\enums;

/**
 * How emails reach Lettermint.
 *
 * @internal
 */
enum Transport: string
{
	case Api = 'api';

	case Smtp = 'smtp';
}
