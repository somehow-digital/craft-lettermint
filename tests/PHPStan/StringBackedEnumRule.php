<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\StringType;

/**
 * Requires string-backed enums, so values stay readable and stable when cases are reordered.
 *
 * @implements Rule<InClassNode>
 */
final readonly class StringBackedEnumRule implements Rule
{
	/**
	 * @param string $namespace The namespace of the plugin.
	 */
	public function __construct(
		private string $namespace,
	) {}

	public function getNodeType(): string
	{
		return InClassNode::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		$class = $node->getClassReflection();

		if (!$class->isEnum() || !str_starts_with($class->getName(), $this->namespace . '\\')) {
			return [];
		}

		if ($class->getBackedEnumType()?->isSuperTypeOf(new StringType())->yes() === true) {
			return [];
		}

		return [
			RuleErrorBuilder::message("The enum {$class->getName()} is not backed by strings, as the enums of the plugin are.")
				->identifier('architecture.stringBackedEnum')
				->build(),
		];
	}
}
