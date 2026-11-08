<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\utilities;

use Craft;
use craft\base\Utility as UtilityBase;
use SomehowDigital\Craft\Lettermint\models\UtilityData;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;

/**
 * Shows the team and usage of the Lettermint team of the team API token, and the statistics and domains of the project
 * of the token (Pro edition), as the `utility` component of the plugin loads them.
 *
 * The page shows the cached data right away, also when it is no longer fresh, or placeholder data in the same layout
 * while nothing is cached, and loads the data with the `content` action of the utility controller unless it is fresh,
 * so the page does not wait for Lettermint.
 *
 * @internal
 */
final class LettermintUtility extends UtilityBase
{
	/**
	 * The action that loads the content and the footer of the page.
	 */
	public const ACTION = 'lettermint/utility/content';

	public static function displayName(): string
	{
		return Craft::t('lettermint', 'Lettermint');
	}

	public static function id(): string
	{
		return 'lettermint';
	}

	public static function icon(): string
	{
		return dirname(__DIR__) . '/icon-mask.svg';
	}

	public static function isSelectable(): bool
	{
		return Plugin::isPro();
	}

	public static function contentHtml(): string
	{
		return self::part('lettermint/utility/content', 'content', self::ACTION);
	}

	public static function toolbarHtml(): string
	{
		Craft::$app->getView()->registerAssetBundle(UtilityAsset::class);

		return Plugin::instance()->getUtility()->render('lettermint/utility/toolbar');
	}

	/**
	 * The footer, which the action of the content replaces as well.
	 */
	public static function footerHtml(): string
	{
		return self::part('lettermint/utility/footer', 'footer');
	}

	/**
	 * Renders a part of the utility or the widget with the data that can be shown right away: the cached data, fresh or
	 * not, or placeholder data in the same layout, shown as a skeleton, while nothing is cached. Unless the data is
	 * fresh, the script of the part loads it with the action, which returns the parts that replace the ones of the page.
	 *
	 * @param string $part The name of the part, which the action returns it by.
	 * @param string|null $action The action that loads the data, or null for a part that another part loads.
	 */
	public static function part(string $template, string $part, ?string $action = null): string
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(UtilityAsset::class);

		$utility = Plugin::instance()->getUtility();
		$data = $utility->cached();
		$placeholder = !$data instanceof UtilityData;
		$data ??= $utility->placeholder();
		$loading = $placeholder || !$utility->isFresh($data);

		if ($loading && $action !== null) {
			$view->registerJsWithVars(static fn(string $part, string $action): string => "LettermintUtility.load({$part}, {$action});", [$part, $action]);
		}

		return $utility->render($template, $data, $loading, $placeholder);
	}
}
