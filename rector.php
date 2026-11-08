<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector;
use Rector\CodingStyle\Rector\ClassLike\NewlineBetweenClassLikeStmtsRector;
use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ClassMethod\NarrowObjectReturnTypeRector;

return RectorConfig::configure()
	->withPaths([
		__DIR__ . '/src',
		__DIR__ . '/tests',
	])
	->withRootFiles()
	// Targets the lowest PHP version in composer.json.
	->withPhpSets()
	->withPreparedSets(
		deadCode: true,
		codeQuality: true,
		codingStyle: true,
		typeDeclarations: true,
		typeDeclarationDocblocks: true,
		privatization: true,
		instanceOf: true,
		earlyReturn: true,
		rectorPreset: true,
		phpunitCodeQuality: true,
	)
	->withComposerBased(phpunit: true)
	->withAttributesSets(phpunit: true)
	->withSkip([
		// Composer keys its loaders by resolved paths, which `..` doesn't match.
		DirnameDirConcatStringToDirectStringPathRector::class,
		// ECS handles member spacing.
		NewlineBetweenClassLikeStmtsRector::class,
		// The public API returns Symfony's transport type, hiding the SDK's.
		NarrowObjectReturnTypeRector::class => [__DIR__ . '/src/Adapter.php'],
	])
	->withImportNames(removeUnusedImports: true)
	->withParallel();
