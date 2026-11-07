<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use craft\helpers\App;

/**
 * The route and tags of a site as they apply, with environment variables resolved (Pro edition).
 *
 * @internal
 */
final readonly class SiteSettings
{
	/**
	 * @param string|null $route The route of the site, or null to use the one of the adapter.
	 * @param Tags $tags The tags of the site, which are added to the ones of the adapter.
	 */
	public function __construct(
		public ?string $route = null,
		public Tags $tags = new Tags(),
	) {}

	/**
	 * Resolves the settings of a site as the adapter stores them.
	 *
	 * @param array{route?:string,tags?:string} $site
	 */
	public static function resolve(array $site): self
	{
		return new self(
			route: Env::value($site['route'] ?? null),
			tags: Tags::parse(App::parseEnv($site['tags'] ?? null)),
		);
	}
}
