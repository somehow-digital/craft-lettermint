<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires every class outside the public API listed in phpstan.neon to be @internal.
 *
 * @implements Rule<InClassNode>
 */
final readonly class PublicApiRule implements Rule
{
	/**
	 * @param string $namespace The namespace of the plugin.
	 * @param list<string> $excluded The namespaces in it that are not part of the plugin, such as its tests.
	 * @param list<string> $classes The classes of the public API.
	 */
	public function __construct(
		private string $namespace,
		private array $excluded,
		private array $classes,
	) {}

	public function getNodeType(): string
	{
		return InClassNode::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		$class = $node->getClassReflection();
		$name = $class->getName();

		if ($class->isAnonymous() || !str_starts_with($name, $this->namespace . '\\')) {
			return [];
		}

		foreach ($this->excluded as $excluded) {
			if (str_starts_with($name, $excluded . '\\')) {
				return [];
			}
		}

		$internal = str_contains((string) $class->getNativeReflection()->getDocComment(), '@internal');
		$public = in_array($name, $this->classes, true);

		if ($internal !== $public) {
			return [];
		}

		$message = $public
			? "{$name} is part of the public API, so it is not marked @internal."
			: "{$name} is not part of the public API, so it is marked @internal, or added to the public API in phpstan.neon.";

		return [RuleErrorBuilder::message($message)->identifier('architecture.publicApi')->build()];
	}
}
