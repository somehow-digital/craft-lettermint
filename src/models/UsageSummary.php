<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use JsonSerializable;

/**
 * Usage of the current period against the plan's monthly volume.
 *
 * Serializes to the health endpoint's `usage`, which monitoring relies on.
 *
 * @internal
 */
final readonly class UsageSummary implements JsonSerializable
{
	/**
	 * Null without an included volume, or with none.
	 */
	public ?float $share;

	/**
	 * @param int|null $included Monthly volume of the plan.
	 * @param string|null $start Null when unknown or not current.
	 * @param string|null $end Null when unknown or not current.
	 */
	public function __construct(
		public int $used,
		public ?int $included = null,
		public ?string $start = null,
		public ?string $end = null,
	) {
		$this->share = $included !== null && $included !== 0 ? $used / $included : null;
	}

	/**
	 * @return array{used:int,included:?int,share:?float}
	 */
	public function jsonSerialize(): array
	{
		return [
			'used' => $this->used,
			'included' => $this->included,
			'share' => $this->share,
		];
	}
}
