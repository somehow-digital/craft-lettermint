<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * Keeps the internal classes of the plugin and of the packages it wraps out of the signatures of its public API, so that
 * projects using the public API never depend on a class that can change in any release. A public member that is marked
 * @internal, such as the getter of an internal component, is not part of the public API.
 *
 * @implements Rule<InClassNode>
 */
final readonly class PublicApiSignatureRule implements Rule
{
	/**
	 * @param string $namespace The namespace of the plugin.
	 * @param list<string> $classes The classes of the public API.
	 * @param list<string> $hidden The namespaces of the packages that the plugin wraps, which its public API hides.
	 */
	public function __construct(
		private string $namespace,
		private array $classes,
		private array $hidden,
	) {}

	public function getNodeType(): string
	{
		return InClassNode::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		$class = $node->getClassReflection();

		if (!in_array($class->getName(), $this->classes, true)) {
			return [];
		}

		return [...$this->checkMethods($class), ...$this->checkProperties($class, $node->getStartLine())];
	}

	/**
	 * The errors for the public methods that the class declares, whose parameters and return types it exposes.
	 *
	 * @return list<IdentifierRuleError>
	 */
	private function checkMethods(ClassReflection $class): array
	{
		$errors = [];

		foreach ($class->getNativeReflection()->getMethods() as $method) {
			if (!$method->isPublic() || $method->getDeclaringClass()->getName() !== $class->getName() || $this->isInternal($method->getDocComment())) {
				continue;
			}

			foreach ($class->getNativeMethod($method->getName())->getVariants() as $variant) {
				$types = [$variant->getReturnType(), ...array_map(static fn(ParameterReflection $parameter): Type => $parameter->getType(), $variant->getParameters())];
				$errors = [...$errors, ...$this->check($class, "{$method->getName()}()", $types, (int) $method->getStartLine())];
			}
		}

		return $errors;
	}

	/**
	 * The errors for the public properties that the class declares, whose types it exposes.
	 *
	 * @return list<IdentifierRuleError>
	 */
	private function checkProperties(ClassReflection $class, int $line): array
	{
		$errors = [];

		foreach ($class->getNativeReflection()->getProperties() as $property) {
			if (!$property->isPublic() || $property->getDeclaringClass()->getName() !== $class->getName() || $this->isInternal($property->getDocComment())) {
				continue;
			}

			$type = $class->getNativeProperty($property->getName())->getReadableType();
			$errors = [...$errors, ...$this->check($class, "\${$property->getName()}", [$type], $line)];
		}

		return $errors;
	}

	/**
	 * The errors for the classes that the types of a member of the public API reference, which it hides.
	 *
	 * @param list<Type> $types
	 * @return list<IdentifierRuleError>
	 */
	private function check(ClassReflection $class, string $member, array $types, int $line): array
	{
		$errors = [];

		foreach ($types as $type) {
			foreach ($type->getReferencedClasses() as $referenced) {
				if (!$this->isHidden($referenced)) {
					continue;
				}

				$errors[] = RuleErrorBuilder::message("{$class->getName()}::{$member} is part of the public API, so it does not expose {$referenced}.")
					->identifier('architecture.publicApiSignature')
					->line($line)
					->build();
			}
		}

		return $errors;
	}

	private function isHidden(string $class): bool
	{
		if (str_starts_with($class, $this->namespace . '\\')) {
			return !in_array($class, $this->classes, true);
		}

		foreach ($this->hidden as $namespace) {
			if (str_starts_with($class, $namespace . '\\')) {
				return true;
			}
		}

		return false;
	}

	private function isInternal(string|false|null $docComment): bool
	{
		return is_string($docComment) && str_contains($docComment, '@internal');
	}
}
