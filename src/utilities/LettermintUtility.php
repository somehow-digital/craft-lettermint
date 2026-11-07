<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\utilities;

use Craft;
use craft\base\Utility as UtilityBase;
use craft\web\View;
use SomehowDigital\Craft\Lettermint\api\TeamApi;
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
class LettermintUtility extends UtilityBase
{
	/**
	 * The URL of the dashboard of Lettermint.
	 */
	public const DASHBOARD_URL = 'https://app.lettermint.co/';

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
		return self::part('lettermint/utility/content', 'content', self::ACTION);
	}

	public static function toolbarHtml(): string
	{
		Craft::$app->getView()->registerAssetBundle(UtilityAsset::class);

		return self::render('lettermint/utility/toolbar');
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

		return self::render($template, $data, $loading, $placeholder);
	}

	/**
	 * Renders a template of the utility or the widget with the data of the `utility` component, and what the templates
	 * link to.
	 *
	 * @param bool $loading Whether the data is loaded again.
	 * @param bool $placeholder Whether the data is placeholder data, which is shown as a skeleton.
	 */
	public static function render(string $template, ?UtilityData $data = null, bool $loading = false, bool $placeholder = false): string
	{
		$view = Craft::$app->getView();
		$view->registerTranslations('lettermint', ['This could not be loaded from Lettermint.']);

		return $view->renderTemplate($template, [
			'data' => $data,
			'loading' => $loading,
			'placeholder' => $placeholder,
			'statsDays' => TeamApi::STATS_DAYS,
			'dashboardUrl' => self::DASHBOARD_URL,
			'utilityPath' => self::path(),
		], View::TEMPLATE_MODE_CP);
	}
}
