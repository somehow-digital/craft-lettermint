<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * Opens and clicks are only set with tracking enabled.
 *
 * @internal
 */
final readonly class StatsTotals
{
	public function __construct(
		public int $sent = 0,
		public int $delivered = 0,
		public int $hardBounced = 0,
		public int $spamComplaints = 0,
		public ?int $opened = null,
		public ?int $clicked = null,
		public int $received = 0,
	) {}
}
