<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\helpers;

use craft\models\Site;

/**
 * Normalizes the route and tags of each site, by site UID.
 *
 * @internal
 */
final class Sites
{
	private const KEYS = ['route', 'tags'];

	/**
	 * Names of the saved sites by UID.
	 *
	 * @param array<Site> $sites
	 * @return array<string,string>
	 */
	public static function names(array $sites): array
	{
		$names = [];

		foreach ($sites as $site) {
			if ($site->uid !== null) {
				$names[$site->uid] = $site->getName();
			}
		}

		return $names;
	}

	/**
	 * Keeps only set routes and tags, dropping sites without any.
	 *
	 * @return array<string,array{route?:string,tags?:string}>
	 */
	public static function normalize(mixed $sites): array
	{
		$normalized = [];

		foreach (is_array($sites) ? $sites : [] as $uid => $options) {
			if (!is_string($uid) || !is_array($options)) {
				continue;
			}

			if ([] !== $site = self::options($options)) {
				$normalized[$uid] = $site;
			}
		}

		return $normalized;
	}

	/**
	 * @param array<mixed> $options
	 * @return array{route?:string,tags?:string}
	 */
	private static function options(array $options): array
	{
		$site = [];

		foreach (self::KEYS as $key) {
			if (null !== $value = Env::nonEmpty($options[$key] ?? null)) {
				$site[$key] = $value;
			}
		}

		return $site;
	}
}
