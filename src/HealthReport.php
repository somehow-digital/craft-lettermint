<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

/**
 * The result of the health checks, whose status is the worst status of its checks.
 *
 * It serializes to the JSON of the health endpoint, which monitoring relies on.
 */
final readonly class HealthReport implements JsonSerializable
{
	public CheckStatus $status;

	/**
	 * @param list<Check> $checks
	 * @param array{used:int,included:?int,share:?float}|null $usage The usage of the current period, with a team token.
	 */
	public function __construct(
		public array $checks,
		public ?array $usage,
		public DateTimeImmutable $date,
	) {
		$this->status = Check::worst($checks);
	}

	/**
	 * @return array{status:string,date:string,checks:list<Check>,usage:?array{used:int,included:?int,share:?float}}
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
