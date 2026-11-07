<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\data;

/**
 * Reads the fields of the objects of Lettermint, whose SDK passes values of an unexpected shape through as they are.
 *
 * @internal
 */
final class Field
{
	/**
	 * A string, or null when it is not one or empty.
	 */
	public static function string(mixed $value): ?string
	{
		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * A whole number, also one sent as a string, or null when it is not a number.
	 */
	public static function int(mixed $value): ?int
	{
		return is_numeric($value) ? (int) $value : null;
	}

	/**
	 * A whole number above zero, also one sent as a string, or null otherwise.
	 */
	public static function positive(mixed $value): ?int
	{
		return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
	}

	/**
	 * The items of a list that are objects of the class, or none when it is not a list.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return list<T>
	 */
	public static function list(mixed $value, string $class): array
	{
		return is_array($value) ? array_values(array_filter($value, static fn(mixed $item): bool => $item instanceof $class)) : [];
	}
}
