<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use craft\helpers\App;
use craft\helpers\MailerHelper;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\Plugin;
use yii\base\Component;

/**
 * Finds the adapter of the email settings of Craft and its resolved settings.
 *
 * The plugin registers it as its `mailSettings` component.
 *
 * @internal
 */
final class MailSettingsService extends Component
{
	/**
	 * Whether Lettermint is the transport of the email settings.
	 */
	public function isLettermint(): bool
	{
		return App::mailSettings()->transportType === Adapter::class;
	}

	/**
	 * The adapter of the email settings, or null when Lettermint is not the transport.
	 */
	public function adapter(): ?Adapter
	{
		if (!$this->isLettermint()) {
			return null;
		}

		/** @var Adapter */
		return MailerHelper::createTransportAdapter(Adapter::class, App::mailSettings()->transportSettings);
	}

	/**
	 * The resolved settings of the adapter of the email settings, or null when Lettermint is not the transport.
	 */
	public function settings(): ?Settings
	{
		return $this->adapter()?->resolve();
	}

	/**
	 * A key for data cached for settings, which other settings, including another system email address, or another
	 * version of the plugin change, as the plugin may shape the data differently.
	 *
	 * The settings are resolved, so a changed value of an environment variable, such as a rotated token, changes the key
	 * as well.
	 */
	public function cacheKey(Settings $settings, string $name): string
	{
		$values = [
			// Like the edition, the version is read without requiring the plugin, so keys are built without it too.
			Plugin::getInstance()?->getVersion(),
			$settings,
		];

		return "lettermint.{$name}." . hash('sha256', serialize($values));
	}
}
