<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use Lettermint\Types\TeamData;
use SomehowDigital\Craft\Lettermint\helpers\Field;

/**
 * The team of the team API token.
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

	public static function fromApi(TeamData $team): self
	{
		return new self(
			name: Field::string($team->get('name')),
			type: Field::string($team->get('type')),
			plan: Field::string($team->get('plan')),
			includedVolume: Field::positive($team->get('included_volume')),
			verifiedAt: Field::string($team->get('verified_at')),
			createdAt: Field::string($team->get('created_at')),
		);
	}
}
