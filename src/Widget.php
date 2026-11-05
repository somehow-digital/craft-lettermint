<?php

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\base\Widget as WidgetBase;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;

/**
 * Shows the usage, statistics and checks of the utility on the dashboard (Pro edition).
 *
 * Users who may open the utility may add the widget, as it shows the same data.
 */
class Widget extends WidgetBase
{
	public static function displayName(): string
	{
		return Craft::t('lettermint', 'Lettermint');
	}

	public static function icon(): ?string
	{
		return __DIR__ . '/icon-mask.svg';
	}

	public static function isSelectable(): bool
	{
		// The parent checks the widgets of the user in the database, which is only needed once the user may see it.
		return Utility::isAccessible() && parent::isSelectable();
	}

	protected static function allowMultipleInstances(): bool
	{
		return false;
	}

	public function getBodyHtml(): ?string
	{
		// A widget that was added before is hidden once the edition or the permission is gone.
		if (!Utility::isAccessible()) {
			return null;
		}

		$view = Craft::$app->getView();
		$view->registerAssetBundle(UtilityAsset::class);

		return $view->renderTemplate('lettermint/widget', Utility::data());
	}
}
