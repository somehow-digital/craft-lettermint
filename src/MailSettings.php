<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use craft\helpers\App;
use craft\helpers\MailerHelper;

/**
 * Finds the adapter of the email settings of Craft and its settings, and keys the data cached for them.
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
	 * A key for data cached for settings, which other settings, another system email address or another version of the
	 * plugin change, as the plugin may shape the data differently.
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
			App::parseEnv(App::mailSettings()->fromEmail),
		];

		return "lettermint.{$name}." . hash('sha256', serialize($values));
	}
}
