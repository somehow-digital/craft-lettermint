<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use Lettermint\Types\TeamUsagePeriodData;
use SomehowDigital\Craft\Lettermint\helpers\Field;

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

	public static function fromApi(TeamUsagePeriodData $period): self
	{
		return new self(
			used: Field::int($period->get('usage')) ?? 0,
			start: Field::string($period->get('period_start')),
			end: Field::string($period->get('period_end')),
		);
	}
}
