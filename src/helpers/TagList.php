<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\helpers;

/**
 * Reads tags as they are written, as comma-separated name=value pairs, for the tags of a message and their validation.
 *
 * @internal
 */
final class TagList
{
	/**
	 * Splits tags into their trimmed name=value pairs, leaving out empty entries.
	 *
	 * @return list<array{0:string,1:?string}> The name and value of each pair, with a null value for entries without `=`.
	 */
	public static function split(mixed $tags): array
	{
		$pairs = [];

		foreach (explode(',', is_string($tags) ? $tags : '') as $entry) {
			$entry = trim($entry);

			if ($entry === '') {
				continue;
			}

			$parts = explode('=', $entry, 2);
			$pairs[] = [trim($parts[0]), isset($parts[1]) ? trim($parts[1]) : null];
		}

		return $pairs;
	}

	/**
	 * The names of the name=value pairs, each once, which are the tags that are sent.
	 *
	 * @return list<string>
	 */
	public static function names(mixed $tags): array
	{
		$names = [];

		foreach (self::split($tags) as [$name, $value]) {
			if ($value !== null) {
				$names[$name] = $name;
			}
		}

		return array_values($names);
	}
}
