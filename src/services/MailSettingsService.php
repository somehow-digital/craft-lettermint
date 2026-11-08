<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use Craft;
use craft\helpers\App;
use craft\helpers\MailerHelper;
use craft\mail\transportadapters\TransportAdapterInterface;
use SomehowDigital\Craft\Lettermint\base\SettingsProviderInterface;
use SomehowDigital\Craft\Lettermint\models\Settings;
use yii\base\Component;

/**
 * Finds the settings of the transport adapter of the email settings of Craft, and caches data for them.
 *
 * The plugin configures it with its adapter and its version through the container, so it depends on neither.
 *
 * The plugin registers it as its `mailSettings` component.
 *
 * @internal
 */
final class MailSettingsService extends Component
{
	/**
	 * The class of the transport adapter of the plugin.
	 *
	 * @var class-string<SettingsProviderInterface&TransportAdapterInterface>|null
	 */
	public ?string $adapter = null;

	/**
	 * The version of the plugin, which changes the keys of the cached data, as another version may shape it differently.
	 */
	public ?string $version = null;

	/**
	 * Whether the adapter of the plugin is the transport of the email settings.
	 */
	public function isLettermint(): bool
	{
		return $this->adapter !== null && App::mailSettings()->transportType === $this->adapter;
	}

	/**
	 * The resolved settings of the adapter of the email settings, or null when it is not the adapter of the plugin.
	 */
	public function settings(): ?Settings
	{
		$adapter = $this->adapter;

		if ($adapter === null || !$this->isLettermint()) {
			return null;
		}

		return MailerHelper::createTransportAdapter($adapter, App::mailSettings()->transportSettings)->resolve();
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
		return "lettermint.{$name}." . hash('sha256', serialize([$this->version, $settings]));
	}

	/**
	 * Data cached for settings, which is loaded again once the cache expires, or each time without a cache.
	 *
	 * @template T
	 * @param callable():T $load
	 * @return T
	 */
	public function cached(Settings $settings, string $name, int $duration, callable $load): mixed
	{
		$cache = Craft::$app->getCache();

		return $cache !== null ? $cache->getOrSet($this->cacheKey($settings, $name), $load, $duration) : $load();
	}

	/**
	 * Data cached for settings, without loading it, or null when none of the class is cached.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T|null
	 */
	public function peek(Settings $settings, string $name, string $class): ?object
	{
		$data = Craft::$app->getCache()?->get($this->cacheKey($settings, $name));

		return $data instanceof $class ? $data : null;
	}

	/**
	 * Caches data for settings, or nothing without a cache.
	 */
	public function store(Settings $settings, string $name, object $data, int $duration): void
	{
		Craft::$app->getCache()?->set($this->cacheKey($settings, $name), $data, $duration);
	}
}
