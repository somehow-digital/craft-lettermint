<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\web\assets\utility;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Styles and script of the utility, widget and Pro settings.
 *
 * @internal
 */
final class UtilityAsset extends AssetBundle
{
	public $sourcePath = __DIR__ . '/dist';

	public $depends = [
		CpAsset::class,
	];

	public $css = [
		'utility.css',
	];

	public $js = [
		'utility.js',
	];
}
