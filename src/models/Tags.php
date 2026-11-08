<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use Countable;
use SomehowDigital\Craft\Lettermint\helpers\TagList;

/**
 * The tags of a message, each name once, which are written as comma-separated name=value pairs.
 *
 * @internal
 */
final readonly class Tags implements Countable
{
	/**
	 * @var list<Tag>
	 */
	public array $tags;

	/**
	 * A later tag replaces an earlier one with the same name.
	 */
	public function __construct(Tag ...$tags)
	{
		$unique = [];

		foreach ($tags as $tag) {
			$unique[$tag->name] = $tag;
		}

		$this->tags = array_values($unique);
	}

	/**
	 * Parses tags into the name/value pairs Lettermint expects, skipping entries without `=`.
	 *
	 * Lettermint validates the tags itself, so the ones set through environment variables are sent as they are. The
	 * `TagsValidator` validates the ones that are written in the settings.
	 */
	public static function parse(mixed $tags): self
	{
		$parsed = [];

		foreach (TagList::split($tags) as [$name, $value]) {
			if ($value !== null) {
				$parsed[] = new Tag($name, $value);
			}
		}

		return new self(...$parsed);
	}

	/**
	 * The tags together with other tags, which replace the ones with the same name.
	 */
	public function merge(self $other): self
	{
		return new self(...$this->tags, ...$other->tags);
	}

	public function count(): int
	{
		return count($this->tags);
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
		return array_map(static fn(Tag $tag): array => $tag->toArray(), $this->tags);
	}
}
