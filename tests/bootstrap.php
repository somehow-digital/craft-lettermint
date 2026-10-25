<?php

/**
 * Loads Craft without a database or an install, so tests can boot a fresh app for each test.
 */

// PHPUnit handles errors, so Craft's error handler is left out.
define('YII_ENABLE_ERROR_HANDLER', false);
define('YII_DEBUG', true);
define('YII_ENV', 'test');

$vendorPath = dirname(__DIR__) . '/vendor';

$loader = require $vendorPath . '/autoload.php';

// Craft and Yii trigger deprecations on newer PHP versions when their classes are compiled, which aren't the plugin's to fix,
// so they are left out while their classes are loaded.
spl_autoload_register(function (string $class) use ($loader) {
	if (str_starts_with($class, 'craft\\') || str_starts_with($class, 'yii\\')) {
		set_error_handler(fn () => true, E_DEPRECATED);

		try {
			$loader->loadClass($class);
		} finally {
			restore_error_handler();
		}
	}
}, true, true);

// Craft replaces the Yii class with its own.
require $vendorPath . '/craftcms/cms/lib/yii2/Yii.php';
require $vendorPath . '/craftcms/cms/src/Craft.php';

$root = sys_get_temp_dir() . '/craft-lettermint-tests-' . getmypid();

foreach (['config', 'storage/runtime', 'storage/logs', 'templates', 'translations', 'web/cpresources'] as $path) {
	if (!is_dir("$root/$path")) {
		mkdir("$root/$path", 0777, true);
	}
}

register_shutdown_function(fn () => craft\helpers\FileHelper::removeDirectory($root));

define('CRAFT_VENDOR_PATH', $vendorPath);
define('CRAFT_TEST_ROOT', $root);

Craft::setAlias('@vendor', $vendorPath);
Craft::setAlias('@craftcms', $vendorPath . '/craftcms/cms');
Craft::setAlias('@lib', $vendorPath . '/craftcms/cms/lib');
Craft::setAlias('@appicons', $vendorPath . '/craftcms/cms/src/icons/solid');
Craft::setAlias('@root', $root);
Craft::setAlias('@config', "$root/config");
Craft::setAlias('@contentMigrations', "$root/migrations");
Craft::setAlias('@storage', "$root/storage");
Craft::setAlias('@templates', "$root/templates");
Craft::setAlias('@translations', "$root/translations");
Craft::setAlias('@webroot', "$root/web");
Craft::setAlias('@web', 'https://example.test');

date_default_timezone_set('UTC');
