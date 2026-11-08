<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * A project of the team, with its routes and domains.
 *
 * @internal
 */
final readonly class Project
{
	public const DELIVERY_MODE_SANDBOX = 'sandbox';

	/**
	 * @param list<Route> $routes
	 * @param list<Domain> $domains
	 */
	public function __construct(
		public ?string $id = null,
		public ?string $name = null,
		public ?string $deliveryMode = null,
		public ?string $tokenLastUsedAt = null,
		public ?string $tokenLastUsedIp = null,
		public array $routes = [],
		public array $domains = [],
	) {}

	/**
	 * The project with the status of its domains taken from the listed domains, as the domains of a project leave it
	 * out.
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
			routes: $this->routes,
			domains: array_map(static fn(Domain $domain): Domain => $domain->withStatus($domain->id === null ? null : $statuses[$domain->id] ?? null), $this->domains),
		);
	}
}
