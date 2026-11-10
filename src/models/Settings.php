<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\enums\Transport;

/**
 * Adapter settings as resolved by `Adapter::resolve()`: env vars resolved, trimmed, empty as null, Pro settings
 * dropped in the standard edition, plus Craft's sender address.
 *
 * @internal
 */
final readonly class Settings
{
	public const DEFAULT_PORT = 465;

	/**
	 * @param string|null $token Project (sending) token, untrimmed.
	 * @param int $port SMTP port, the default one when not set or unknown.
	 * @param array<array-key,SiteSettings> $sites By site UID (Pro edition).
	 * @param string|null $senderEmail Craft's system email address.
	 * @param string|null $senderName Craft's sender name.
	 * @param string|null $replyToEmail Craft's reply-to address.
	 * @param string|null $template Craft's HTML email template.
	 */
	public function __construct(
		#[SensitiveParameter]
		public ?string $token = null,
		public Transport $transport = Transport::Api,
		public int $port = self::DEFAULT_PORT,
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
		public ?string $senderName = null,
		public ?string $replyToEmail = null,
		public ?string $template = null,
	) {}

	/**
	 * Empty for sites without their own.
	 */
	public function site(string $siteUid): SiteSettings
	{
		return $this->sites[$siteUid] ?? new SiteSettings();
	}
}
