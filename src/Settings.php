<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use SensitiveParameter;

/**
 * The settings of the adapter as they apply: with environment variables resolved, trimmed, empty ones as null, and the
 * settings of the pro edition left out in the standard edition.
 *
 * They are resolved once by `Adapter::resolve()`, so the transport, the checks and the cache keys read neither the
 * environment nor the edition themselves.
 */
final readonly class Settings
{
	/**
	 * @param string|null $token The project (sending) token, untrimmed, as the transport sends it as it is.
	 * @param array<string,SiteSettings> $sites The route and tags of each site, keyed by site UID (Pro edition).
	 * @param string|null $teamToken The team API token (Pro edition).
	 * @param string|null $projectId The ID of the Lettermint project of the token (Pro edition).
	 * @param string|null $webhookSecret The signing secret of the Lettermint webhook (Pro edition).
	 * @param string|null $healthToken The token that requests to the health endpoint authenticate with (Pro edition).
	 */
	public function __construct(
		#[SensitiveParameter]
		public ?string $token = null,
		public ?string $route = null,
		public Tags $tags = new Tags(),
		public array $sites = [],
		#[SensitiveParameter]
		public ?string $teamToken = null,
		public ?string $projectId = null,
		#[SensitiveParameter]
		public ?string $webhookSecret = null,
		#[SensitiveParameter]
		public ?string $healthToken = null,
	) {}

	/**
	 * The route and tags of a site, which are empty for a site without its own.
	 */
	public function site(string $siteUid): SiteSettings
	{
		return $this->sites[$siteUid] ?? new SiteSettings();
	}
}
