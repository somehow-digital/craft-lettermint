<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\utilities;

use Craft;
use craft\base\Utility as UtilityBase;
use SomehowDigital\Craft\Lettermint\helpers\TeamApi;
use SomehowDigital\Craft\Lettermint\models\UtilityData;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;

/**
 * Shows the team and usage of the Lettermint team of the team API token, and the statistics and domains of the project
 * of the token (Pro edition), as the `utility` component of the plugin loads them.
 */
class LettermintUtility extends UtilityBase
{
	/**
	 * The URL of the dashboard of Lettermint.
	 */
	public const DASHBOARD_URL = 'https://dash.lettermint.co/';

	public static function displayName(): string
	{
		return Craft::t('lettermint', 'Lettermint');
	}

	public static function id(): string
	{
		return 'lettermint';
	}

	/**
	 * The path of the utility in the control panel.
	 */
	public static function path(): string
	{
		return 'utilities/' . self::id();
	}

	public static function icon(): string
	{
		return dirname(__DIR__) . '/icon-mask.svg';
	}

	public static function isSelectable(): bool
	{
		return Plugin::isPro();
	}

	/**
	 * Whether the current user may see the data of the utility, on its page, in the widget or by refreshing it.
	 */
	public static function isAccessible(): bool
	{
		return self::isSelectable() && Craft::$app->getUtilities()->checkAuthorization(self::class);
	}

	public static function contentHtml(): string
	{
		return self::render('content');
	}

	public static function toolbarHtml(): string
	{
		return self::render('toolbar');
	}

	public static function footerHtml(): string
	{
		return self::render('footer');
	}

	private static function render(string $part): string
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(UtilityAsset::class);

		return $view->renderTemplate("lettermint/utility/{$part}", self::variables());
	}

	/**
	 * The variables of the templates of the utility and the widget: the data of the `utility` component, and what the
	 * templates link to.
	 *
	 * @return array{data:UtilityData,statsDays:int,dashboardUrl:string,utilityPath:string}
	 */
	public static function variables(): array
	{
		return [
			'data' => Plugin::instance()->getUtility()->data(),
			'statsDays' => TeamApi::STATS_DAYS,
			'dashboardUrl' => self::DASHBOARD_URL,
			'utilityPath' => self::path(),
		];
	}
}
