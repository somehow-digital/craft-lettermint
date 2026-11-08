<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClassPropertyNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids the static properties of the plugin, which hold state across requests in long-running processes, such as the
 * queue runner, and between tests. State lives in the components, which Yii creates for each application.
 *
 * @implements Rule<ClassPropertyNode>
 */
final readonly class StaticStateRule implements Rule
{
	/**
	 * @param string $namespace The namespace of the plugin.
	 * @param list<string> $excluded The namespaces in it that are not part of the plugin, such as its tests.
	 */
	public function __construct(
		private string $namespace,
		private array $excluded,
	) {}

	public function getNodeType(): string
	{
		return ClassPropertyNode::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		$class = $node->getClassReflection()->getName();

		if (!$node->isStatic() || !str_starts_with($class, $this->namespace . '\\')) {
			return [];
		}

		foreach ($this->excluded as $excluded) {
			if (str_starts_with($class, $excluded . '\\')) {
				return [];
			}
		}

		return [
			RuleErrorBuilder::message("{$class}::\${$node->getName()} is static, so it holds state beyond the application, which the components hold instead.")
				->identifier('architecture.staticState')
				->build(),
		];
	}
}
