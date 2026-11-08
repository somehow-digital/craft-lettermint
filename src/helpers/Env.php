<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\helpers;

use craft\helpers\App;

/**
 * Resolves settings that accept environment variables.
 *
 * @internal
 */
final class Env
{
	/**
	 * The value with env vars resolved and trimmed, or null when empty.
	 */
	public static function value(?string $value): ?string
	{
		return self::nonEmpty(App::parseEnv($value));
	}

	/**
	 * The trimmed string, or null when not a string or empty.
	 */
	public static function nonEmpty(mixed $value): ?string
	{
		return is_string($value) && trim($value) !== '' ? trim($value) : null;
	}
}
