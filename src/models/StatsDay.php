<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use Lettermint\Types\StatsDailyData;
use SomehowDigital\Craft\Lettermint\helpers\Field;

/**
 * The emails of a project sent on a day.
 */
final readonly class StatsDay
{
	public function __construct(
		public ?string $date = null,
		public int $sent = 0,
	) {}

	public static function fromApi(StatsDailyData $day): self
	{
		return new self(
			date: Field::string($day->get('date')),
			sent: Field::int($day->get('sent')) ?? 0,
		);
	}
}
