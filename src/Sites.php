<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use craft\models\Site;

/**
 * Normalizes and validates the route and tags of each site, keyed by site UID, and finds the current site.
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
	 * The errors of the tags of each site, with environment variables resolved, prefixed with the name of the site.
	 *
	 * The tags of a site are also counted together with the tags of the adapter, as they are merged when sending.
	 *
	 * @param array<string,array{route?:string,tags?:string}> $sites
	 * @param list<array{name:string,value:string}> $adapterTags
	 * @return list<string>
	 */
	public static function tagErrors(array $sites, array $adapterTags): array
	{
		$all = [];

		foreach ($sites as $uid => $options) {
			$siteTags = App::parseEnv($options['tags'] ?? null);
			$errors = Tags::errors($siteTags);

			// Too many tags of the adapter are reported on the adapter itself.
			if ($errors === [] && count($adapterTags) <= Tags::MAX && count(Tags::merge($adapterTags, Tags::parse($siteTags))) > Tags::MAX) {
				$errors[] = Craft::t('lettermint', 'There can be at most {max} tags together with the tags above.', ['max' => Tags::MAX]);
			}

			if ($errors === []) {
				continue;
			}

			$site = self::name($uid);

			foreach ($errors as $error) {
				$all[] = "{$site}: {$error}";
			}
		}

		return $all;
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

	private static function name(string $uid): string
	{
		try {
			return Craft::$app->getSites()->getSiteByUid($uid, true)->getName();
		} catch (SiteNotFoundException) {
			return $uid;
		}
	}
}
