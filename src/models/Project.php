<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use Lettermint\Types\DomainData;
use Lettermint\Types\ProjectData;
use Lettermint\Types\RouteData;
use SomehowDigital\Craft\Lettermint\helpers\Field;

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

	public static function fromApi(ProjectData $project): self
	{
		return new self(
			id: Field::string($project->get('id')),
			name: Field::string($project->get('name')),
			deliveryMode: Field::string($project->get('delivery_mode')),
			tokenLastUsedAt: Field::string($project->get('token_last_used_at')),
			tokenLastUsedIp: Field::string($project->get('token_last_used_ip')),
			routes: array_map(Route::fromApi(...), Field::list($project->get('routes'), RouteData::class)),
			domains: array_map(Domain::fromApi(...), Field::list($project->get('domains'), DomainData::class)),
		);
	}

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
