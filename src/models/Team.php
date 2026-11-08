<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * The team of the team API token.
 *
 * @internal
 */
final readonly class Team
{
	public const TYPE_BUSINESS = 'business';

	public function __construct(
		public ?string $name = null,
		public ?string $type = null,
		public ?string $plan = null,
		/** The emails included in the monthly plan, if it includes any. */
		public ?int $includedVolume = null,
		public ?string $verifiedAt = null,
		public ?string $createdAt = null,
	) {}
}
