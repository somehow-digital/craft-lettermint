<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\enums;

/**
 * What a check is about; the prefix of its ID.
 *
 * @internal
 */
enum CheckType: string
{
	/**
	 * The project API token.
	 */
	case Token = 'token';

	/**
	 * The team API token.
	 */
	case TeamToken = 'teamToken';

	/**
	 * Plan usage.
	 */
	case Usage = 'usage';

	/**
	 * The project failed to load.
	 */
	case Project = 'project';

	/**
	 * The project's delivery mode.
	 */
	case Delivery = 'delivery';

	/**
	 * The domain of the sender address.
	 */
	case Sender = 'sender';

	/**
	 * The route of the adapter or a site.
	 */
	case Route = 'route';
}
