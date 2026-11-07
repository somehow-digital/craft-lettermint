<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use craft\helpers\App;
use craft\helpers\MailerHelper;

/**
 * Finds the adapter of the email settings of Craft, and keys the data cached for it.
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
	 * A key for data cached for the settings of an adapter, which other settings, another system email address or
	 * another version of the plugin change, as the plugin may shape the data differently.
	 *
	 * The settings are hashed with environment variables resolved, so a changed value, such as a rotated token,
	 * changes the key as well.
	 */
	public static function cacheKey(Adapter $adapter, string $name): string
	{
		$values = [
			Plugin::getInstance()?->getVersion(),
			$adapter->getAttributes(),
			App::mailSettings()->fromEmail,
		];

		array_walk_recursive($values, static function (mixed &$value): void {
			if (is_string($value)) {
				$value = App::parseEnv($value);
			}
		});

		return "lettermint.{$name}." . hash('sha256', serialize($values));
	}
}
