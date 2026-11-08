<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * @internal
 */
final readonly class Route
{
	public const TYPE_INBOUND = 'inbound';

	public function __construct(
		public ?string $slug = null,
		public ?string $type = null,
		public bool $default = false,
	) {}

	/**
	 * All but inbound routes.
	 */
	public function outgoing(): bool
	{
		return $this->type !== self::TYPE_INBOUND;
	}
}
