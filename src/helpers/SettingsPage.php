<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\helpers;

use Craft;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\services\HealthService;
use SomehowDigital\Craft\Lettermint\services\WebhooksService;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;

/**
 * Renders the settings of the adapter in the email settings of Craft.
 *
 * @internal
 */
final class SettingsPage
{
	public static function render(Adapter $adapter): string
	{
		$view = Craft::$app->getView();

		// The styles hold the badge of the pro settings.
		if (Plugin::isPro()) {
			$view->registerAssetBundle(UtilityAsset::class);
		}

		return $view->renderTemplate('lettermint/settings', [
			'adapter' => $adapter,
			'pro' => Plugin::isPro(),
			'siteRows' => self::siteRows($adapter),
			'webhookUrl' => WebhooksService::url(),
			'healthUrl' => HealthService::url(),
			'healthTokenMinLength' => Adapter::HEALTH_TOKEN_MIN_LENGTH,
		]);
	}

	/**
	 * A row of the route and tags of each saved site, also the disabled ones, keyed by site UID.
	 *
	 * @return array<string,array{heading:string,route:string,tags:string}>
	 */
	private static function siteRows(Adapter $adapter): array
	{
		$rows = [];

		foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
			if ($site->uid === null) {
				continue;
			}

			$rows[$site->uid] = [
				'heading' => $site->getName(),
				'route' => $adapter->sites[$site->uid]['route'] ?? '',
				'tags' => $adapter->sites[$site->uid]['tags'] ?? '',
			];
		}

		return $rows;
	}
}
