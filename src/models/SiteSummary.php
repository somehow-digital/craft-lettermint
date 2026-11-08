<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * The email settings and the route and tags that emails of a site are sent with.
 *
 * @internal
 */
final readonly class SiteSummary
{
	/**
	 * @param string|null $route Null for the project's default route.
	 * @param Tags $tags Including the inherited ones.
	 * @param list<string> $own The settings the site sets, rather than inheriting them.
	 */
	public function __construct(
		public string $name,
		public ?string $senderEmail,
		public ?string $senderName,
		public ?string $replyToEmail,
		public ?string $template,
		public ?string $route,
		public Tags $tags,
		public array $own,
	) {}

	/**
	 * Whether the site sets the setting, rather than inheriting it.
	 */
	public function isOwn(string $setting): bool
	{
		return in_array($setting, $this->own, true);
	}
}
