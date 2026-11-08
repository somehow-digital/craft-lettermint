<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\PHPStan;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\FunctionParameterClosureThisExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use SomehowDigital\Craft\Lettermint\Tests\TestCase;

/**
 * Types `$this` in test and hook closures as the test case, as Pest binds them.
 */
final class PestClosureThisExtension implements FunctionParameterClosureThisExtension
{
	private const FUNCTIONS = ['it', 'test', 'beforeEach', 'afterEach'];

	public function isFunctionSupported(FunctionReflection $functionReflection, ParameterReflection $parameter): bool
	{
		return in_array($functionReflection->getName(), self::FUNCTIONS, true) && $parameter->getName() === 'closure';
	}

	public function getClosureThisTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, ParameterReflection $parameter, Scope $scope): Type
	{
		return new ObjectType(TestCase::class);
	}
}
