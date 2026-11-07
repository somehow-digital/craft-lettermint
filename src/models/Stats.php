<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use Lettermint\Types\StatsDailyData;
use Lettermint\Types\StatsData;
use Lettermint\Types\StatsTotalsData;
use SomehowDigital\Craft\Lettermint\helpers\Field;

/**
 * The message statistics of a project, in total and per day.
 *
 * @internal
 */
final readonly class Stats
{
	/**
	 * @param list<StatsDay> $daily
	 */
	public function __construct(
		public ?string $from = null,
		public ?string $to = null,
		public StatsTotals $totals = new StatsTotals(),
		public array $daily = [],
	) {}

	public static function fromApi(StatsData $stats): self
	{
		$totals = $stats->get('totals');

		return new self(
			from: Field::string($stats->get('from')),
			to: Field::string($stats->get('to')),
			totals: $totals instanceof StatsTotalsData ? StatsTotals::fromApi($totals) : new StatsTotals(),
			daily: array_map(StatsDay::fromApi(...), Field::list($stats->get('daily'), StatsDailyData::class)),
		);
	}
}
