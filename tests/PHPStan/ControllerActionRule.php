<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use yii\base\Controller;

/**
 * Keeps the public methods of controllers to their actions and the methods of Yii and Craft that they override. Yii
 * routes requests to the public `action` methods, so any other public method is either an action by mistake or logic
 * that belongs to a service, which keeps the controllers thin.
 *
 * @implements Rule<InClassNode>
 */
final readonly class ControllerActionRule implements Rule
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

		if (!str_starts_with($class->getName(), $this->namespace . '\\') || !$class->is(Controller::class)) {
			return [];
		}

		$parent = $class->getParentClass();
		$errors = [];

		foreach ($class->getNativeReflection()->getMethods() as $method) {
			$name = $method->getName();

			if (
				!$method->isPublic()
				|| $method->getDeclaringClass()->getName() !== $class->getName()
				|| preg_match('/^action[A-Z]/', $name) === 1
				|| $parent?->hasNativeMethod($name) === true
			) {
				continue;
			}

			$errors[] = $this->error($class->getName(), $name, (int) $method->getStartLine());
		}

		return $errors;
	}

	private function error(string $class, string $method, int $line): IdentifierRuleError
	{
		return RuleErrorBuilder::message("{$class}::{$method}() is public, but neither an action named action…() nor a method of Yii or Craft that it overrides. Logic belongs to a service.")
			->identifier('architecture.controllerAction')
			->line($line)
			->build();
	}
}
