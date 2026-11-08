<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * A verified Lettermint webhook payload (Pro edition).
 */
final readonly class WebhookPayload
{
	/**
	 * @param string $event E.g. `message.delivered`; see `WebhooksService::TYPE_*`.
	 * @param string|null $id Stays the same across retries.
	 * @param array<mixed> $data E.g. the `recipient`.
	 */
	public function __construct(
		public string $event,
		public ?string $id = null,
		public ?string $timestamp = null,
		public array $data = [],
	) {}
}
