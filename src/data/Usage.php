<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\data;

use Lettermint\Types\TeamUsageDetailData;
use Lettermint\Types\TeamUsagePeriodData;

/**
 * The usage of the team, with its current period, if Lettermint sent one.
 */
final readonly class Usage
{
	public function __construct(
		public ?Period $current = null,
	) {}

	public static function fromApi(TeamUsageDetailData $usage): self
	{
		$period = $usage->get('current_period');

		return new self($period instanceof TeamUsagePeriodData ? Period::fromApi($period) : null);
	}
}
