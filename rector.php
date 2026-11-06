<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector;
use Rector\CodingStyle\Rector\ClassLike\NewlineBetweenClassLikeStmtsRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
	->withPaths([
		__DIR__ . '/src',
		__DIR__ . '/tests',
	])
	->withRootFiles()
	// Upgrades to the lowest version of PHP that the plugin supports, as Composer requires it.
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
		// Composer keys its registered loaders by the resolved path, which a path with `..` doesn't match.
		DirnameDirConcatStringToDirectStringPathRector::class,
		// The coding standard separates the members of classes, and keeps related constants grouped.
		NewlineBetweenClassLikeStmtsRector::class,
	])
	->withImportNames(removeUnusedImports: true)
	->withParallel();
