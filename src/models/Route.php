<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * @internal
 */
final readonly class Route
{
	public const TYPE_INBOUND = 'inbound';

	/**
	 * @param bool|null $trackOpens Null when Lettermint doesn't tell, e.g. without access to the route settings.
	 * @param bool|null $trackClicks Null when Lettermint doesn't tell.
	 */
	public function __construct(
		public ?string $slug = null,
		public ?string $type = null,
		public bool $default = false,
		public ?bool $trackOpens = null,
		public ?bool $trackClicks = null,
	) {}

	/**
	 * All but inbound routes.
	 */
	public function outgoing(): bool
	{
		return $this->type !== self::TYPE_INBOUND;
	}
}
