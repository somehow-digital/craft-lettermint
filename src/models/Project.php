<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * @internal
 */
final readonly class Project
{
	public const DELIVERY_MODE_SANDBOX = 'sandbox';

	/**
	 * @param bool|null $smtpEnabled Null when Lettermint doesn't tell.
	 * @param bool|null $redactEmailContent Whether the content of emails is hidden in Lettermint. Null when unknown.
	 * @param list<Route> $routes
	 * @param list<Domain> $domains
	 */
	public function __construct(
		public ?string $id = null,
		public ?string $name = null,
		public ?string $deliveryMode = null,
		public ?string $tokenLastUsedAt = null,
		public ?string $tokenLastUsedIp = null,
		public ?bool $smtpEnabled = null,
		public ?bool $redactEmailContent = null,
		public ?string $createdAt = null,
		public array $routes = [],
		public array $domains = [],
	) {}

	/**
	 * Null when Lettermint doesn't tell the delivery mode.
	 */
	public function isSandbox(): ?bool
	{
		return $this->deliveryMode === null ? null : $this->deliveryMode === self::DELIVERY_MODE_SANDBOX;
	}

	/**
	 * Takes domain statuses from the domain list, as a project's domains lack them.
	 *
	 * @param list<Domain> $listed
	 */
	public function withStatuses(array $listed): self
	{
		$statuses = [];

		foreach ($listed as $domain) {
			if ($domain->id !== null) {
				$statuses[$domain->id] = $domain->status;
			}
		}

		return new self(
			id: $this->id,
			name: $this->name,
			deliveryMode: $this->deliveryMode,
			tokenLastUsedAt: $this->tokenLastUsedAt,
			tokenLastUsedIp: $this->tokenLastUsedIp,
			smtpEnabled: $this->smtpEnabled,
			redactEmailContent: $this->redactEmailContent,
			createdAt: $this->createdAt,
			routes: $this->routes,
			domains: array_map(static fn(Domain $domain): Domain => $domain->withStatus($domain->id === null ? null : $statuses[$domain->id] ?? null), $this->domains),
		);
	}
}
