<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use SomehowDigital\Craft\Lettermint\enums\CheckStatus;

/**
 * Serializes to the health endpoint's JSON, which monitoring relies on.
 *
 * @internal
 */
final readonly class HealthReport implements JsonSerializable
{
	public CheckStatus $status;

	/**
	 * @param list<Check> $checks
	 * @param UsageSummary|null $usage Only with a team token.
	 */
	public function __construct(
		public array $checks,
		public ?UsageSummary $usage,
		public DateTimeImmutable $date,
	) {
		$this->status = Check::worst($checks);
	}

	/**
	 * @return array{status:string,date:string,checks:list<Check>,usage:?UsageSummary}
	 */
	public function jsonSerialize(): array
	{
		return [
			'status' => $this->status->value,
			'date' => $this->date->format(DateTimeInterface::ATOM),
			'checks' => $this->checks,
			'usage' => $this->usage,
		];
	}
}
