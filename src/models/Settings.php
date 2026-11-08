<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use SensitiveParameter;

/**
 * Adapter settings as resolved by `Adapter::resolve()`: env vars resolved, trimmed, empty as null, Pro settings
 * dropped in the standard edition, plus Craft's sender address.
 *
 * @internal
 */
final readonly class Settings
{
	/**
	 * @param string|null $token Project (sending) token, untrimmed.
	 * @param array<string,SiteSettings> $sites By site UID (Pro edition).
	 * @param string|null $senderEmail Craft's system email address.
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
		public ?string $senderEmail = null,
	) {}

	/**
	 * Empty for sites without their own.
	 */
	public function site(string $siteUid): SiteSettings
	{
		return $this->sites[$siteUid] ?? new SiteSettings();
	}
}
