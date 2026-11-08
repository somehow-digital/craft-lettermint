<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\PHPStan;

use craft\queue\BaseJob;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClassPropertyNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

/**
 * Limits queue job properties to scalars and arrays, as jobs may run later with another plugin version. Reference
 * records by ID or UID.
 *
 * @implements Rule<ClassPropertyNode>
 */
final readonly class QueueJobPropertyRule implements Rule
{
	/**
	 * @param string $namespace The namespace of the plugin.
	 */
	public function __construct(
		private string $namespace,
	) {}

	public function getNodeType(): string
	{
		return ClassPropertyNode::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		$class = $node->getClassReflection();

		if (
			$node->isStatic()
			|| !str_starts_with($class->getName(), $this->namespace . '\\')
			|| !$class->is(BaseJob::class)
		) {
			return [];
		}

		$type = $class->getNativeProperty($node->getName())->getReadableType();

		if ($this->isSerializable($type)) {
			return [];
		}

		return [
			RuleErrorBuilder::message("{$class->getName()}::\${$node->getName()} is serialized with the job, so it holds scalars or arrays of them, not {$type->describe(VerbosityLevel::typeOnly())}.")
				->identifier('architecture.queueJobProperty')
				->build(),
		];
	}

	/**
	 * Only scalars, null, and arrays of them.
	 */
	private function isSerializable(Type $type): bool
	{
		if ($type->getReferencedClasses() !== [] || !$type->isObject()->no()) {
			return false;
		}

		return $type->isIterable()->no() || $this->isSerializable($type->getIterableValueType());
	}
}
