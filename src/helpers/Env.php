<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\helpers;

use craft\helpers\App;

/**
 * Resolves the settings that can be set through environment variables.
 *
 * @internal
 */
final class Env
{
	/**
	 * A value with environment variables resolved, trimmed, or null when it is empty.
	 */
	public static function value(?string $value): ?string
	{
		return self::nonEmpty(App::parseEnv($value));
	}

	/**
	 * A value trimmed, or null when it is not a string or empty.
	 */
	public static function nonEmpty(mixed $value): ?string
	{
		return is_string($value) && trim($value) !== '' ? trim($value) : null;
	}
}
