<?php

declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Option;

return ECSConfig::configure()
	->withPaths([
		__DIR__ . '/src',
	])
	->withRootFiles()
	->withFileExtensions(['php'])
	->withPreparedSets(psr12: true)
	->withSpacing(Option::INDENTATION_TAB, "\n")
	->withParallel();
