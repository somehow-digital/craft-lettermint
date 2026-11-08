<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Goto_;
use PhpParser\Node\Stmt\InlineHTML;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids constructs that print, exit or escape scope, which the forbidden functions list can't cover. Craft handles
 * output and ending the request.
 *
 * @implements Rule<Node>
 */
final class ForbiddenConstructRule implements Rule
{
	/** @var array<class-string<Node>,string> */
	private const CONSTRUCTS = [
		Echo_::class => 'echo',
		InlineHTML::class => 'inline HTML',
		Print_::class => 'print',
		Exit_::class => 'exit',
		Goto_::class => 'goto',
		Global_::class => 'global',
	];

	public function getNodeType(): string
	{
		return Node::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		$construct = self::CONSTRUCTS[$node::class] ?? null;

		if ($construct === null) {
			return [];
		}

		return [
			RuleErrorBuilder::message("The language construct {$construct} is forbidden. Output goes through the responses of Craft.")
				->identifier('architecture.forbiddenConstruct')
				->build(),
		];
	}
}
