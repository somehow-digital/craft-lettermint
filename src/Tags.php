<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;

/**
 * Parses, merges and validates tags, written as comma-separated name=value pairs.
 *
 * @internal
 */
final class Tags
{
	/**
	 * The most tags Lettermint accepts for a message.
	 */
	public const MAX = 20;

	/**
	 * Parses tags into the name/value pairs Lettermint expects, skipping entries without `=`.
	 *
	 * Lettermint validates the tags itself, so the ones set through environment variables are sent as they are.
	 *
	 * @return list<array{name:string,value:string}>
	 */
	public static function parse(mixed $tags): array
	{
		$parsed = [];

		foreach (self::split($tags) as [$name, $value]) {
			if ($value !== null) {
				$parsed[] = ['name' => $name, 'value' => $value];
			}
		}

		return self::merge($parsed);
	}

	/**
	 * Merges lists of tags, a later tag replacing an earlier one with the same name.
	 *
	 * @param list<array{name:string,value:string}> ...$lists
	 * @return list<array{name:string,value:string}>
	 */
	public static function merge(array ...$lists): array
	{
		$merged = [];

		foreach ($lists as $tags) {
			foreach ($tags as $tag) {
				$merged[$tag['name']] = $tag;
			}
		}

		return array_values($merged);
	}

	/**
	 * The errors of tags, following the rules of Lettermint for tag names and values.
	 *
	 * @return list<string>
	 */
	public static function errors(mixed $tags): array
	{
		$errors = [];
		$names = [];

		foreach (self::split($tags) as [$name, $value]) {
			if ($value === null) {
				$errors[] = Craft::t('lettermint', '“{tag}” is not a name=value pair.', ['tag' => $name]);
				continue;
			}

			if (null !== $error = self::nameError($name, isset($names[$name]))) {
				$errors[] = $error;
			}

			if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) !== 1) {
				$errors[] = Craft::t('lettermint', 'Tag value “{value}” must be 1 to 64 letters, digits, underscores or hyphens.', ['value' => $value]);
			}

			// Only the keys are looked up, with `isset()`, which any value but null passes.
			$names[$name] = true; // @pest-mutate-ignore: TrueToFalse
		}

		if (count($names) > self::MAX) {
			$errors[] = Craft::t('lettermint', 'There can be at most {max} tags.', ['max' => self::MAX]);
		}

		return $errors;
	}

	/**
	 * The error of a tag name, or null when it is valid.
	 *
	 * @param bool $used Whether an earlier tag has the same name.
	 */
	private static function nameError(string $name, bool $used): ?string
	{
		return match (true) {
			preg_match('/^[A-Za-z0-9_-]{1,32}$/', $name) !== 1 => Craft::t('lettermint', 'Tag name “{name}” must be 1 to 32 letters, digits, underscores or hyphens.', ['name' => $name]),
			stripos($name, '__lettermint') === 0 => Craft::t('lettermint', 'Tag name “{name}” is reserved.', ['name' => $name]),
			$used => Craft::t('lettermint', 'Tag name “{name}” is used more than once.', ['name' => $name]),
			default => null,
		};
	}

	/**
	 * Splits tags into their trimmed name=value pairs, leaving out empty entries.
	 *
	 * @return list<array{0:string,1:?string}> The name and value of each pair, with a null value for entries without `=`.
	 */
	private static function split(mixed $tags): array
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
}
