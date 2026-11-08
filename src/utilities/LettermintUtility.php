<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\utilities;

use Craft;
use craft\base\Utility as UtilityBase;
use craft\web\View;
use SomehowDigital\Craft\Lettermint\models\Stats;
use SomehowDigital\Craft\Lettermint\models\UtilityData;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;

/**
 * Shows the team and usage of the Lettermint team of the team API token, and the statistics and domains of the project
 * of the token (Pro edition), as the `status` component of the plugin loads them.
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

	/**
	 * The URL of the dashboard of Lettermint.
	 */
	public const DASHBOARD_URL = 'https://app.lettermint.co/';

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

		return self::render('lettermint/utility/toolbar');
	}

	/**
	 * Whether the current user may see the data of the utility, on its page, in the widget or by refreshing it, which
	 * requires the utility to be selectable, i.e. the pro edition.
	 */
	public static function isAccessible(): bool
	{
		return self::isSelectable() && Craft::$app->getUtilities()->checkAuthorization(self::class);
	}

	/**
	 * Renders a template of the utility or the widget with the data, and what the templates link to.
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
			'statsDays' => Stats::DAYS,
			'dashboardUrl' => self::DASHBOARD_URL,
			'utilityPath' => 'utilities/' . self::id(),
		], View::TEMPLATE_MODE_CP);
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

		$status = Plugin::instance()->getStatus();
		$data = $status->cached();
		$placeholder = !$data instanceof UtilityData;
		$data ??= UtilityData::placeholder($status->settings());
		$loading = $placeholder || !$status->isFresh($data);

		if ($loading && $action !== null) {
			$view->registerJsWithVars(static fn(string $part, string $action): string => "LettermintUtility.load({$part}, {$action});", [$part, $action]);
		}

		return self::render($template, $data, $loading, $placeholder);
	}
}
