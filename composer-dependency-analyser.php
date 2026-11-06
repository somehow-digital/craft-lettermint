<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
	// Craft loads its `Craft` class itself, so Composer cannot autoload it.
	->ignoreUnknownClasses(['Craft'])
	// Yii is the framework of Craft, which requires the versions that it supports.
	->ignoreErrorsOnPackages(['yiisoft/yii2', 'yiisoft/yii2-symfonymailer'], [ErrorType::SHADOW_DEPENDENCY])
	// The analyser takes `Env::` of the plugin for the `env()` function of Illuminate, which Craft requires.
	->ignoreErrorsOnPackage('illuminate/support', [ErrorType::SHADOW_DEPENDENCY])
	// The test case extends the one of PHPUnit, whose version Pest requires.
	->ignoreErrorsOnPackageAndPath('phpunit/phpunit', __DIR__ . '/tests/TestCase.php', [ErrorType::SHADOW_DEPENDENCY])
	// The PHPStan extensions of the tests use the parser that PHPStan comes with.
	->ignoreErrorsOnPackageAndPath('nikic/php-parser', __DIR__ . '/tests/PHPStan', [ErrorType::SHADOW_DEPENDENCY]);
