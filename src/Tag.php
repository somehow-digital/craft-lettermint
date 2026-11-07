<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

/**
 * A tag of a message, as a name=value pair.
 *
 * @internal
 */
final readonly class Tag
{
	public function __construct(
		public string $name,
		public string $value,
	) {}

	/**
	 * The tag as Lettermint expects it.
	 *
	 * @return array{name:string,value:string}
	 */
	public function toArray(): array
	{
		return ['name' => $this->name, 'value' => $this->value];
	}
}
