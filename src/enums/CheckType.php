<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\enums;

/**
 * What a check is about, which its ID starts with.
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
	 * The usage of the plan.
	 */
	case Usage = 'usage';

	/**
	 * The project, when it could not be loaded.
	 */
	case Project = 'project';

	/**
	 * The delivery mode of the project.
	 */
	case Delivery = 'delivery';

	/**
	 * The domain of the system email address.
	 */
	case Sender = 'sender';

	/**
	 * The route of the adapter, or of a site.
	 */
	case Route = 'route';
}
