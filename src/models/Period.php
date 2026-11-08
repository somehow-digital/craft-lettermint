<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * A billing period of the team, with the emails sent in it.
 *
 * @internal
 */
final readonly class Period
{
	public function __construct(
		public int $used = 0,
		public ?string $start = null,
		public ?string $end = null,
	) {}
}
