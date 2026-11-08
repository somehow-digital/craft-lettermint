<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\helpers;

use craft\helpers\UrlHelper;

/**
 * The URL of the health endpoint.
 *
 * @internal
 */
final class HealthEndpoint
{
	/**
	 * Authenticates monitors without headers. Craft reserves `token`.
	 */
	public const QUERY_PARAM = 'key';

	/**
	 * A site URL, so it's reachable without a control panel session.
	 *
	 * @param string $actionTrigger From the general config.
	 * @param string|null $key The health token, to authenticate with the query parameter.
	 */
	public static function url(string $actionTrigger, ?string $key = null): string
	{
		return UrlHelper::siteUrl("{$actionTrigger}/lettermint/health", $key !== null ? [self::QUERY_PARAM => $key] : null);
	}
}
