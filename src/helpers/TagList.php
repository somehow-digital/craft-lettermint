<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\helpers;

/**
 * Reads tags written as comma-separated name=value pairs.
 *
 * @internal
 */
final class TagList
{
	/**
	 * Splits tags into trimmed pairs, skipping empty entries.
	 *
	 * @return list<array{0:string,1:?string}> Name and value; null value for entries without `=`.
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
	 * Unique tag names.
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
