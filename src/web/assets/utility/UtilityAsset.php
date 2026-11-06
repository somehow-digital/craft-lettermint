<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\web\assets\utility;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The styles of the Lettermint utility, the dashboard widget and the Pro settings.
 */
class UtilityAsset extends AssetBundle
{
	public $sourcePath = __DIR__ . '/dist';

	public $depends = [
		CpAsset::class,
	];

	public $css = [
		'utility.css',
	];
}
