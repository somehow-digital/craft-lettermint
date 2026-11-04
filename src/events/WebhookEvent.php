<?php

namespace SomehowDigital\Craft\Lettermint\events;

use Lettermint\WebhookPayload;
use yii\base\Event;

/**
 * A verified delivery of a Lettermint webhook (Pro edition).
 *
 * Lettermint delivers an event again when the endpoint fails or does not answer in time, so a delivery can arrive
 * more than once. Its `id` in the payload stays the same, which handlers can use to skip repeated deliveries.
 */
class WebhookEvent extends Event
{
	/**
	 * The type of the delivery, the Lettermint event, e.g. `message.delivered`, as in the `TYPE_*` constants of `Webhooks`.
	 */
	public string $type;

	/**
	 * The payload of the delivery, with the ID of the delivery, the Lettermint event, its time and its data.
	 */
	public WebhookPayload $payload;
}
