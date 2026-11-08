<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use PhpParser\Node\VarLikeIdentifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Keeps the service locator of Yii, `Craft::$app` and `Yii::$app`, and its container, out of the domain and the
 * infrastructure, which get what they need from whoever uses them, as Yii advises for models. It sees the dependencies
 * that deptrac.yaml cannot, since the service locator returns them without naming them.
 *
 * @implements Rule<StaticPropertyFetch>
 */
final readonly class ServiceLocatorRule implements Rule
{
	/** The classes of Yii and Craft, with their static properties that locate services. */
	private const LOCATORS = ['Craft' => ['app', 'container'], 'Yii' => ['app', 'container']];

	/**
	 * @param string $namespace The namespace of the plugin.
	 * @param list<string> $layers The namespaces in it that do not locate services.
	 */
	public function __construct(
		private string $namespace,
		private array $layers,
	) {}

	public function getNodeType(): string
	{
		return StaticPropertyFetch::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		if (!$node->class instanceof Name || !$node->name instanceof VarLikeIdentifier) {
			return [];
		}

		$class = ltrim($scope->resolveName($node->class), '\\');
		$property = $node->name->toString();

		if (!in_array($property, self::LOCATORS[$class] ?? [], true) || !$this->isInLayer((string) $scope->getNamespace())) {
			return [];
		}

		return [
			RuleErrorBuilder::message("{$class}::\${$property} locates services, which the domain and the infrastructure get from whoever uses them.")
				->identifier('architecture.serviceLocator')
				->build(),
		];
	}

	private function isInLayer(string $namespace): bool
	{
		foreach ($this->layers as $layer) {
			$layer = $this->namespace . '\\' . $layer;

			if ($namespace === $layer || str_starts_with($namespace, $layer . '\\')) {
				return true;
			}
		}

		return false;
	}
}
