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
	// Constants are left out, so related ones stay grouped.
	->withConfiguredRule(ClassAttributesSeparationFixer::class, [
		'elements' => [
			'method' => 'one',
			'property' => 'one',
		],
	])
	// Keeps PER-CS's space in `new class ()`.
	->withConfiguredRule(ClassDefinitionFixer::class, [
		'inline_constructor_arguments' => false,
		'space_before_parenthesis' => true,
	])
	// Allows one-line docblocks on constants and properties.
	->withConfiguredRule(PhpdocLineSpanFixer::class, [
		'const' => null,
		'property' => null,
	])
	->withSkip([
		// Keeps short arrays, such as Yii model rules, on one line.
		ArrayListItemNewlineFixer::class,
		ArrayOpenerAndCloserNewlineFixer::class,
		StandaloneLineInMultilineArrayFixer::class,
		// PER-CS has no space after `!`.
		NotOperatorWithSuccessorSpaceFixer::class,
	])
	->withSpacing(Option::INDENTATION_TAB, "\n")
	->withParallel();
