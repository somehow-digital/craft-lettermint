<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * Project message statistics, in total and per day.
 *
 * @internal
 */
final readonly class Stats
{
	/**
	 * Days of statistics, including today.
	 */
	public const DAYS = 28;

	/**
	 * @param list<StatsDay> $daily
	 */
	public function __construct(
		public ?string $from = null,
		public ?string $to = null,
		public StatsTotals $totals = new StatsTotals(),
		public array $daily = [],
	) {}
}
