<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * The usage of the team, with its current period, if Lettermint sent one.
 *
 * @internal
 */
final readonly class Usage
{
	public function __construct(
		public ?Period $current = null,
	) {}
}
