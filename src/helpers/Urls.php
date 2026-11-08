<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\helpers;

use Craft;
use craft\helpers\UrlHelper;

/**
 * The routes and URLs of the endpoints that Lettermint and monitors call, on the primary site.
 *
 * They depend on nothing of the plugin, so the settings of the adapter link to the endpoints without depending on the
 * services behind them.
 *
 * @internal
 */
final class Urls
{
	/**
	 * The route of the health endpoint, as an action of the plugin.
	 */
	public const HEALTH_ACTION = 'lettermint/health';

	/**
	 * The route of the webhook endpoint, as an action of the plugin.
	 */
	public const WEBHOOK_ACTION = 'lettermint/webhook';

	/**
	 * The URL of the health endpoint, for monitoring.
	 */
	public static function health(): string
	{
		return self::action(self::HEALTH_ACTION);
	}

	/**
	 * The URL of the webhook endpoint, for the webhook in Lettermint.
	 */
	public static function webhook(): string
	{
		return self::action(self::WEBHOOK_ACTION);
	}

	private static function action(string $action): string
	{
		return UrlHelper::siteUrl(Craft::$app->getConfig()->getGeneral()->actionTrigger . '/' . $action);
	}
}
