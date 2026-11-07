<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\widgets;

use Craft;
use craft\base\Widget as WidgetBase;
use SomehowDigital\Craft\Lettermint\utilities\LettermintUtility;

/**
 * Shows the usage, statistics and checks of the utility on the dashboard (Pro edition).
 *
 * Users who may open the utility may add the widget, as it shows the same data. Like the utility, it shows the data
 * right away, and loads it with the `widget` action of the utility controller unless it is fresh.
 *
 * @internal
 */
class LettermintWidget extends WidgetBase
{
	/**
	 * The action that loads the body of the widget.
	 */
	public const ACTION = 'lettermint/utility/widget';

	public static function displayName(): string
	{
		return Craft::t('lettermint', 'Lettermint');
	}

	public static function icon(): string
	{
		return LettermintUtility::icon();
	}

	public static function isSelectable(): bool
	{
		// The parent checks the widgets of the user in the database, which is only needed once the user may see it.
		return LettermintUtility::isAccessible() && parent::isSelectable();
	}

	protected static function allowMultipleInstances(): bool
	{
		return false;
	}

	public function getBodyHtml(): ?string
	{
		// A widget that was added before is hidden once the edition or the permission is gone.
		if (!LettermintUtility::isAccessible()) {
			return null;
		}

		return LettermintUtility::part('lettermint/widget', 'widget', self::ACTION);
	}
}
