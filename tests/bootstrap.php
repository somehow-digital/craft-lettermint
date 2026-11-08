<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use craft\helpers\FileHelper;

/**
 * Loads Craft without a database or install.
 */

// PHPUnit handles errors instead of Craft.
define('YII_ENABLE_ERROR_HANDLER', false);
define('YII_DEBUG', true);
define('YII_ENV', 'test');

$vendorPath = dirname(__DIR__) . '/vendor';

// Reuses the autoloader if Pest already loaded it.
require_once $vendorPath . '/autoload.php';
$loader = ClassLoader::getRegisteredLoaders()[$vendorPath];

// Silences Craft's and Yii's compile-time deprecations on newer PHP; not ours to fix.
spl_autoload_register(function (string $class) use ($loader): void {
	if (str_starts_with($class, 'craft\\') || str_starts_with($class, 'yii\\')) {
		set_error_handler(fn(): true => true, E_DEPRECATED);

		try {
			$loader->loadClass($class);
		} finally {
			restore_error_handler();
		}
	}
}, true, true);

// Craft replaces the Yii class with its own.
require_once $vendorPath . '/craftcms/cms/lib/yii2/Yii.php';
require_once $vendorPath . '/craftcms/cms/src/Craft.php';

$root = sys_get_temp_dir() . '/craft-lettermint-tests-' . getmypid();

foreach (['config', 'storage/runtime', 'storage/logs', 'templates', 'translations', 'web/cpresources'] as $path) {
	if (!is_dir("{$root}/{$path}")) {
		mkdir("{$root}/{$path}", 0777, true);
	}
}

// Signs CP forms, e.g. the utility refresh.
file_put_contents("{$root}/config/general.php", "<?php\n\nreturn ['securityKey' => 'lettermint-tests'];\n");

register_shutdown_function(fn() => FileHelper::removeDirectory($root));

define('CRAFT_VENDOR_PATH', $vendorPath);
define('CRAFT_TEST_ROOT', $root);

Craft::setAlias('@vendor', $vendorPath);
Craft::setAlias('@craftcms', $vendorPath . '/craftcms/cms');
Craft::setAlias('@lib', $vendorPath . '/craftcms/cms/lib');
Craft::setAlias('@appicons', $vendorPath . '/craftcms/cms/src/icons/solid');
Craft::setAlias('@root', $root);
Craft::setAlias('@config', "{$root}/config");
Craft::setAlias('@contentMigrations', "{$root}/migrations");
Craft::setAlias('@storage', "{$root}/storage");
Craft::setAlias('@templates', "{$root}/templates");
Craft::setAlias('@translations', "{$root}/translations");
Craft::setAlias('@webroot', "{$root}/web");
Craft::setAlias('@web', 'https://example.test');

date_default_timezone_set('UTC');
