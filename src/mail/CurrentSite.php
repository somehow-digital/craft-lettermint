<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\mail;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\models\Site;

/**
 * Finds the current site, which Craft sets to the message's site while sending.
 *
 * @internal
 */
final class CurrentSite
{
	/**
	 * Null when there is none, e.g. during install.
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
