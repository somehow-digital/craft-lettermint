<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * @internal
 */
final readonly class StatsDay
{
	public function __construct(
		public ?string $date = null,
		public int $sent = 0,
	) {}
}
