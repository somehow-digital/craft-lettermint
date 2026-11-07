<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\data;

use Lettermint\Types\RouteData;

/**
 * A route of a project.
 */
final readonly class Route
{
	public const TYPE_INBOUND = 'inbound';

	public function __construct(
		public ?string $slug = null,
		public ?string $type = null,
		public bool $default = false,
	) {}

	public static function fromApi(RouteData $route): self
	{
		return new self(
			slug: Field::string($route->get('slug')),
			type: Field::string($route->get('route_type')),
			default: $route->get('is_default') === true,
		);
	}

	/**
	 * Whether emails are sent through the route, as all but inbound routes do.
	 */
	public function outgoing(): bool
	{
		return $this->type !== self::TYPE_INBOUND;
	}
}
