<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use craft\helpers\App;
use SomehowDigital\Craft\Lettermint\helpers\Env;

/**
 * A site's route and tags, and the email settings Craft overrides for it, with env vars resolved (Pro edition).
 *
 * @internal
 */
final readonly class SiteSettings
{
	/**
	 * @param string|null $route Null to use the adapter's route.
	 * @param Tags $tags Merged into the adapter's tags.
	 * @param string|null $senderEmail Craft's system email address of the site, null to use the global one.
	 * @param string|null $senderName Null to use the global one.
	 * @param string|null $replyToEmail Null to use the global one.
	 * @param string|null $template Null to use the global one.
	 */
	public function __construct(
		public ?string $route = null,
		public Tags $tags = new Tags(),
		public ?string $senderEmail = null,
		public ?string $senderName = null,
		public ?string $replyToEmail = null,
		public ?string $template = null,
	) {}

	/**
	 * @param array{route?:string,tags?:string} $site
	 * @param array<mixed> $mail Craft's site overrides of the email settings.
	 */
	public static function resolve(array $site, array $mail = []): self
	{
		$value = static fn(string $key): ?string => is_string($mail[$key] ?? null) ? Env::value($mail[$key]) : null;

		return new self(
			route: Env::value($site['route'] ?? null),
			tags: Tags::parse(App::parseEnv($site['tags'] ?? null)),
			senderEmail: $value('fromEmail'),
			senderName: $value('fromName'),
			replyToEmail: $value('replyToEmail'),
			template: $value('template'),
		);
	}
}
