<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\ClassNotation\ClassAttributesSeparationFixer;
use PhpCsFixer\Fixer\ClassNotation\ClassDefinitionFixer;
use PhpCsFixer\Fixer\Operator\NotOperatorWithSuccessorSpaceFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocLineSpanFixer;
use PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer;
use Symplify\CodingStandard\Fixer\ArrayNotation\ArrayListItemNewlineFixer;
use Symplify\CodingStandard\Fixer\ArrayNotation\ArrayOpenerAndCloserNewlineFixer;
use Symplify\CodingStandard\Fixer\ArrayNotation\StandaloneLineInMultilineArrayFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Option;

return ECSConfig::configure()
	->withPaths([
		__DIR__ . '/src',
		__DIR__ . '/tests',
	])
	->withRootFiles()
	->withFileExtensions(['php'])
	->withPreparedSets(
		perCs: true,
		arrays: true,
		comments: true,
		docblocks: true,
		spaces: true,
		namespaces: true,
		controlStructures: true,
		casing: true,
		cleanup: true,
		cleanCode: true,
	)
	->withRules([DeclareStrictTypesFixer::class])
	// Constants are left out, so that related ones stay grouped.
	->withConfiguredRule(ClassAttributesSeparationFixer::class, [
		'elements' => [
			'method' => 'one',
			'property' => 'one',
		],
	])
	// The common sets register the fixer without options, which would drop the space of PER-CS in `new class ()`.
	->withConfiguredRule(ClassDefinitionFixer::class, [
		'inline_constructor_arguments' => false,
		'space_before_parenthesis' => true,
	])
	// Short docblocks of constants and properties may stay on one line, such as a single `@var`.
	->withConfiguredRule(PhpdocLineSpanFixer::class, [
		'const' => null,
		'property' => null,
	])
	->withSkip([
		// Short arrays stay on one line, such as the rules of Yii models, which are lists of mixed items.
		ArrayListItemNewlineFixer::class,
		ArrayOpenerAndCloserNewlineFixer::class,
		StandaloneLineInMultilineArrayFixer::class,
		// PER-CS writes the negation without a space after it.
		NotOperatorWithSuccessorSpaceFixer::class,
	])
	->withSpacing(Option::INDENTATION_TAB, "\n")
	->withParallel();
