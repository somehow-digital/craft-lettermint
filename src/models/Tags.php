<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use SomehowDigital\Craft\Lettermint\helpers\TagList;

/**
 * The tags of a message, each name once, which are written as comma-separated name=value pairs.
 *
 * @internal
 */
final readonly class Tags
{
	/**
	 * @param array<array-key,string> $tags The values of the tags by their names, which PHP keys as integers when they are
	 * numeric.
	 */
	public function __construct(
		public array $tags = [],
	) {}

	/**
	 * Parses tags into the name/value pairs Lettermint expects, skipping entries without `=`. A later tag replaces an
	 * earlier one with the same name.
	 *
	 * Lettermint validates the tags itself, so the ones set through environment variables are sent as they are. The
	 * `TagsValidator` validates the ones that are written in the settings.
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
	 * The tags together with other tags, which replace the ones with the same name.
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
	 * The tags as Lettermint expects them.
	 *
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
