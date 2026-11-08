<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\mail;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\models\Site;

/**
 * Finds the current site, which Craft sets to the site of a message while sending.
 *
 * @internal
 */
final class CurrentSite
{
	/**
	 * The current site, or null when there is none, e.g. while Craft is installed.
	 */
	public static function find(): ?Site
	{
		try {
			return Craft::$app->getSites()->getCurrentSite();
		} catch (SiteNotFoundException) {
			return null;
		}
	}
}
