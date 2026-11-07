<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\helpers;

use Craft;
use craft\helpers\App;
use craft\helpers\MailerHelper;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\Plugin;

/**
 * Finds the adapter of the email settings of Craft and its settings, and caches data for them.
 *
 * @internal
 */
final class MailSettings
{
	/**
	 * Whether Lettermint is the transport of the email settings.
	 */
	public static function isLettermint(): bool
	{
		return App::mailSettings()->transportType === Adapter::class;
	}

	/**
	 * The adapter of the email settings, or null when Lettermint is not the transport.
	 */
	public static function adapter(): ?Adapter
	{
		if (!self::isLettermint()) {
			return null;
		}

		/** @var Adapter */
		return MailerHelper::createTransportAdapter(Adapter::class, App::mailSettings()->transportSettings);
	}

	/**
	 * The resolved settings of the adapter of the email settings, or null when Lettermint is not the transport.
	 */
	public static function settings(): ?Settings
	{
		return self::adapter()?->resolve();
	}

	/**
	 * A key for data cached for settings, which other settings, including another system email address, or another
	 * version of the plugin change, as the plugin may shape the data differently.
	 *
	 * The settings are resolved, so a changed value of an environment variable, such as a rotated token, changes the key
	 * as well.
	 */
	public static function cacheKey(Settings $settings, string $name): string
	{
		$values = [
			// Like the edition, the version is read without requiring the plugin, so keys are built without it too.
			Plugin::getInstance()?->getVersion(),
			$settings,
		];

		return "lettermint.{$name}." . hash('sha256', serialize($values));
	}

	/**
	 * Data cached for settings, which is loaded again once the cache expires, or each time without a cache.
	 *
	 * @template T
	 * @param callable():T $load
	 * @return T
	 */
	public static function cached(Settings $settings, string $name, int $duration, callable $load): mixed
	{
		$cache = Craft::$app->getCache();

		return $cache !== null ? $cache->getOrSet(self::cacheKey($settings, $name), $load, $duration) : $load();
	}

	/**
	 * Data cached for settings, without loading it, or null when none of the class is cached.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T|null
	 */
	public static function peek(Settings $settings, string $name, string $class): ?object
	{
		$data = Craft::$app->getCache()?->get(self::cacheKey($settings, $name));

		return $data instanceof $class ? $data : null;
	}

	/**
	 * Caches data for settings, or nothing without a cache.
	 */
	public static function store(Settings $settings, string $name, object $data, int $duration): void
	{
		Craft::$app->getCache()?->set(self::cacheKey($settings, $name), $data, $duration);
	}
}
