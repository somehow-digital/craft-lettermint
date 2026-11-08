<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\widgets;

use Craft;
use craft\base\Widget as WidgetBase;
use SomehowDigital\Craft\Lettermint\utilities\LettermintUtility;

/**
 * Shows the utility's usage and statistics on the dashboard (Pro edition).
 *
 * Available to users with access to the utility, and loads data the same way.
 *
 * @internal
 */
final class LettermintWidget extends WidgetBase
{
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
		// Checked first, as the parent queries the database.
		return LettermintUtility::isAccessible() && parent::isSelectable();
	}

	protected static function allowMultipleInstances(): bool
	{
		return false;
	}

	public function getBodyHtml(): ?string
	{
		// Hides widgets added before the edition or permission was lost.
		if (!LettermintUtility::isAccessible()) {
			return null;
		}

		return LettermintUtility::part('lettermint/widget', 'widget', self::ACTION);
	}
}
