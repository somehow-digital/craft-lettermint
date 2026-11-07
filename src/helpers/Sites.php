<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\helpers;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\models\Site;

/**
 * Normalizes the route and tags of each site, keyed by site UID, and finds the current site.
 *
 * @internal
 */
final class Sites
{
	/**
	 * The settings of each site.
	 */
	private const KEYS = ['route', 'tags'];

	/**
	 * Reduces the sites to the routes and tags that are set, dropping sites without any.
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
	 * The settings of a site that are set.
	 *
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

	/**
	 * The current site, which Craft sets to the site of a message while sending, or null when there is none, e.g. while
	 * Craft is installed.
	 */
	public static function current(): ?Site
	{
		try {
			return Craft::$app->getSites()->getCurrentSite();
		} catch (SiteNotFoundException) {
			return null;
		}
	}
}
