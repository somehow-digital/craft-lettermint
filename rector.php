<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector;
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
		typeDeclarations: true,
		earlyReturn: true,
	)
	->withSkip([
		// Composer keys its registered loaders by the resolved path, which a path with `..` doesn't match.
		DirnameDirConcatStringToDirectStringPathRector::class,
	])
	->withImportNames(removeUnusedImports: true)
	->withParallel();
