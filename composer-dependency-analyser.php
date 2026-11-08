<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
	// Craft loads `Craft` itself.
	->ignoreUnknownClasses(['Craft'])
	// Required by Craft.
	->ignoreErrorsOnPackages(['yiisoft/yii2', 'yiisoft/yii2-symfonymailer'], [ErrorType::SHADOW_DEPENDENCY])
	// False positive: the plugin's `Env::` is mistaken for Illuminate's `env()`.
	->ignoreErrorsOnPackage('illuminate/support', [ErrorType::SHADOW_DEPENDENCY])
	// Required by Pest.
	->ignoreErrorsOnPackageAndPath('phpunit/phpunit', __DIR__ . '/tests/TestCase.php', [ErrorType::SHADOW_DEPENDENCY])
	// Bundled with PHPStan.
	->ignoreErrorsOnPackageAndPath('nikic/php-parser', __DIR__ . '/tests/PHPStan', [ErrorType::SHADOW_DEPENDENCY])
	// Required by Craft.
	->ignoreErrorsOnPackageAndPath('twig/twig', __DIR__ . '/tests/PHPStan/ArchitectureRules.php', [ErrorType::SHADOW_DEPENDENCY]);
