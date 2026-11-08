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
 * Shows the team, usage, project statistics and domains (Pro edition).
 *
 * Renders cached or placeholder data right away and loads fresh data asynchronously.
 *
 * @internal
 */
final class LettermintUtility extends UtilityBase
{
	/**
	 * Loads the content and footer.
	 */
	public const ACTION = 'lettermint/utility/content';

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
	 * Whether the current user may see the data, anywhere. Requires the Pro edition.
	 */
	public static function isAccessible(): bool
	{
		return self::isSelectable() && Craft::$app->getUtilities()->checkAuthorization(self::class);
	}

	/**
	 * @param bool $loading Whether the data is being reloaded.
	 * @param bool $placeholder Whether to render a skeleton.
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
	 * Replaced by the content action as well.
	 */
	public static function footerHtml(): string
	{
		return self::part('lettermint/utility/footer', 'footer');
	}

	/**
	 * Renders a part with cached data, or a skeleton without. Stale parts are reloaded by the script.
	 *
	 * @param string $part The name the action returns it by.
	 * @param string|null $action Null when another part's action loads it.
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
