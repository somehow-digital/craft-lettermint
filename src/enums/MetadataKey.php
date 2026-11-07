<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\enums;

/**
 * The keys of the Lettermint metadata that the plugin adds to a message.
 */
enum MetadataKey: string
{
	/**
	 * The key of the system message.
	 */
	case Email = 'craft_email';

	/**
	 * The handle of the site.
	 */
	case Site = 'craft_site';

	/**
	 * The ID of the user the message is sent to.
	 */
	case User = 'craft_user';

	/**
	 * The language of the message.
	 */
	case Language = 'craft_language';

	/**
	 * The environment of Craft.
	 */
	case Environment = 'craft_environment';
}
