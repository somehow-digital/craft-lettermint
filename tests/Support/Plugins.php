<?php

namespace SomehowDigital\Craft\Lettermint\Tests\Support;

use craft\base\PluginInterface;
use craft\services\Plugins as PluginsService;

/**
 * The plugins service, serving the plugins a test installs instead of the ones stored in the database.
 */
class Plugins extends PluginsService
{
	/** @var array<string,PluginInterface> */
	private array $installed = [];

	public function install(PluginInterface $plugin): void
	{
		$this->installed[$plugin->id] = $plugin;
	}

	public function getPlugin(string $handle): ?PluginInterface
	{
		return $this->installed[$handle] ?? null;
	}

	public function isPluginInstalled(string $handle): bool
	{
		return isset($this->installed[$handle]);
	}

	public function isPluginEnabled(string $handle): bool
	{
		return isset($this->installed[$handle]);
	}
}
