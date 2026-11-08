<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use SomehowDigital\Craft\Lettermint\helpers\TagList;

/**
 * Message tags, unique by name.
 *
 * @internal
 */
final readonly class Tags
{
	/**
	 * @param array<array-key,string> $tags Values by name; numeric names become integer keys.
	 */
	public function __construct(
		public array $tags = [],
	) {}

	/**
	 * Skips entries without `=`; later tags override earlier ones of the same name.
	 *
	 * Tags from env vars are sent unvalidated; Lettermint validates them.
	 */
	public static function parse(mixed $tags): self
	{
		$parsed = [];

		foreach (TagList::split($tags) as [$name, $value]) {
			if ($value !== null) {
				$parsed[$name] = $value;
			}
		}

		return new self($parsed);
	}

	/**
	 * The other tags override ones of the same name.
	 */
	public function merge(self $other): self
	{
		return new self(array_replace($this->tags, $other->tags));
	}

	public function isEmpty(): bool
	{
		return $this->tags === [];
	}

	/**
	 * @return list<array{name:string,value:string}>
	 */
	public function toArray(): array
	{
		$tags = [];

		foreach ($this->tags as $name => $value) {
			$tags[] = ['name' => (string) $name, 'value' => $value];
		}

		return $tags;
	}
}
