<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use Lettermint\Types\StatsInboundData;
use Lettermint\Types\StatsTotalsData;
use SomehowDigital\Craft\Lettermint\helpers\Field;

/**
 * The emails of a project in total, with the opened and clicked ones only when tracking is enabled.
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

	public static function fromApi(StatsTotalsData $totals): self
	{
		$inbound = $totals->get('inbound');

		return new self(
			sent: Field::int($totals->get('sent')) ?? 0,
			delivered: Field::int($totals->get('delivered')) ?? 0,
			hardBounced: Field::int($totals->get('hard_bounced')) ?? 0,
			spamComplaints: Field::int($totals->get('spam_complaints')) ?? 0,
			opened: Field::int($totals->get('opened')),
			clicked: Field::int($totals->get('clicked')),
			received: $inbound instanceof StatsInboundData ? Field::int($inbound->get('received')) ?? 0 : 0,
		);
	}
}
