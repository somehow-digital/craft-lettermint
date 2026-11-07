<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use JsonSerializable;

/**
 * The usage of the current period, compared with the monthly volume included in the plan.
 *
 * It serializes to the usage of the health endpoint, which monitoring relies on, without the dates of the period.
 *
 * @internal
 */
final readonly class UsageSummary implements JsonSerializable
{
	/**
	 * The share of the included volume that is used, or null without an included volume.
	 */
	public ?float $share;

	/**
	 * @param int|null $included The monthly volume included in the plan, or null when it is not known.
	 * @param string|null $start The start of the period, or null when it is not known or does not contain the time.
	 * @param string|null $end The end of the period, or null when it is not known or does not contain the time.
	 */
	public function __construct(
		public int $used,
		public ?int $included = null,
		public ?string $start = null,
		public ?string $end = null,
	) {
		$this->share = $included !== null ? $used / $included : null;
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
