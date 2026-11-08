<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

/**
 * The payload of a verified delivery of a Lettermint webhook (Pro edition).
 */
final readonly class WebhookPayload
{
	/**
	 * @param string $event The Lettermint event, e.g. `message.delivered`, as in the `TYPE_*` constants of `WebhooksService`.
	 * @param string|null $id The ID of the delivery, which stays the same when Lettermint delivers it again.
	 * @param string|null $timestamp The time of the event, as Lettermint sends it.
	 * @param array<mixed> $data The data of the event, e.g. its `recipient`.
	 */
	public function __construct(
		public string $event,
		public ?string $id = null,
		public ?string $timestamp = null,
		public array $data = [],
	) {}
}
