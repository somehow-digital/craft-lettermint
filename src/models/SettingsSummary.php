<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use SomehowDigital\Craft\Lettermint\enums\Transport;

/**
 * The settings that editors may see, without tokens and secrets.
 *
 * @internal
 */
final readonly class SettingsSummary
{
	/**
	 * @param int $port SMTP port, only used with the SMTP transport.
	 * @param string|null $route Null for the project's default route.
	 * @param bool $webhooks Whether the webhook endpoint is on.
	 * @param bool $health Whether the health endpoint is on.
	 * @param list<SiteSummary> $sites
	 */
	public function __construct(
		public Transport $transport,
		public int $port,
		public ?string $senderEmail,
		public ?string $senderName,
		public ?string $replyToEmail,
		public ?string $template,
		public ?string $route,
		public Tags $tags,
		public bool $webhooks,
		public bool $health,
		public array $sites,
	) {}

	/**
	 * @param array<string,string> $siteNames All sites, by UID.
	 */
	public static function from(Settings $settings, array $siteNames): self
	{
		$sites = [];

		foreach ($siteNames as $uid => $name) {
			$site = $settings->site($uid);
			$own = array_keys(array_filter([
				'senderEmail' => $site->senderEmail !== null,
				'senderName' => $site->senderName !== null,
				'replyToEmail' => $site->replyToEmail !== null,
				'template' => $site->template !== null,
				'route' => $site->route !== null,
				'tags' => !$site->tags->isEmpty(),
			]));

			// As Craft and the transport apply them.
			$sites[] = new SiteSummary(
				name: $name,
				senderEmail: $site->senderEmail ?? $settings->senderEmail,
				senderName: $site->senderName ?? $settings->senderName,
				replyToEmail: $site->replyToEmail ?? $settings->replyToEmail,
				template: $site->template ?? $settings->template,
				route: $site->route ?? $settings->route,
				tags: $settings->tags->merge($site->tags),
				own: $own,
			);
		}

		return new self(
			transport: $settings->transport,
			port: $settings->port,
			senderEmail: $settings->senderEmail,
			senderName: $settings->senderName,
			replyToEmail: $settings->replyToEmail,
			template: $settings->template,
			route: $settings->route,
			tags: $settings->tags,
			webhooks: $settings->webhookSecret !== null,
			health: $settings->healthToken !== null,
			sites: $sites,
		);
	}
}
