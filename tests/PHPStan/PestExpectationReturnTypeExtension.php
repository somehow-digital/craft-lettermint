<?php

namespace SomehowDigital\Craft\Lettermint\Tests\PHPStan;

use Pest\Expectation;
use Pest\Mixins\Expectation as ExpectationMixin;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;

/**
 * Types the expectations that Pest chains as expectations, which its mixin types as the mixin, so that `not` and the
 * other properties of an expectation stay known along a chain.
 *
 * It is registered for each class that has the mixin, the expectation and its opposite.
 */
final class PestExpectationReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
	/**
	 * @param class-string $class
	 */
	public function __construct(
		private readonly string $class,
	) {
	}

	public function getClass(): string
	{
		return $this->class;
	}

	public function isMethodSupported(MethodReflection $methodReflection): bool
	{
		return $methodReflection->getDeclaringClass()->getName() === ExpectationMixin::class;
	}

	public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
	{
		$type = ParametersAcceptorSelector::selectFromArgs($scope, $methodCall->getArgs(), $methodReflection->getVariants())->getReturnType();

		if ($type->getObjectClassNames() !== [ExpectationMixin::class]) {
			return null;
		}

		return new GenericObjectType(Expectation::class, [$type->getTemplateType(ExpectationMixin::class, 'TValue')]);
	}
}
