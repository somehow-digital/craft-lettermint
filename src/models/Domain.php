<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use Lettermint\Types\DomainData;
use Lettermint\Types\DomainListData;
use SomehowDigital\Craft\Lettermint\helpers\Field;

/**
 * A domain of the team, whose status only the list of domains has.
 *
 * @internal
 */
final readonly class Domain
{
	public const STATUS_VERIFIED = 'verified';
	public const STATUS_PARTIALLY_VERIFIED = 'partially_verified';
	public const STATUS_PENDING_VERIFICATION = 'pending_verification';
	public const STATUS_FAILED_VERIFICATION = 'failed_verification';

	public function __construct(
		public ?string $id = null,
		public ?string $domain = null,
		public ?string $status = null,
		public ?string $statusChangedAt = null,
	) {}

	public static function fromApi(DomainData|DomainListData $domain): self
	{
		return new self(
			id: Field::string($domain->get('id')),
			domain: Field::string($domain->get('domain')),
			status: Field::string($domain->get('status')),
			statusChangedAt: Field::string($domain->get('status_changed_at')),
		);
	}

	public function withStatus(?string $status): self
	{
		return new self(
			id: $this->id,
			domain: $this->domain,
			status: $status,
			statusChangedAt: $this->statusChangedAt,
		);
	}
}
