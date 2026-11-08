<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use craft\helpers\App;
use SomehowDigital\Craft\Lettermint\helpers\Env;

/**
 * A site's route and tags, with env vars resolved (Pro edition).
 *
 * @internal
 */
final readonly class SiteSettings
{
	/**
	 * @param string|null $route Null to use the adapter's route.
	 * @param Tags $tags Merged into the adapter's tags.
	 */
	public function __construct(
		public ?string $route = null,
		public Tags $tags = new Tags(),
	) {}

	/**
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
