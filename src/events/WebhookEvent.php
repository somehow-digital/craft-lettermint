<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\events;

use SomehowDigital\Craft\Lettermint\models\WebhookPayload;
use yii\base\Event;

/**
 * A verified Lettermint webhook delivery (Pro edition).
 *
 * Lettermint retries failed or timed-out deliveries, so one can arrive more than once. Use the payload's `id`
 * to skip duplicates.
 */
final class WebhookEvent extends Event
{
	/**
	 * The Lettermint event, e.g. `message.delivered`; see `WebhooksService::TYPE_*`.
	 */
	public string $type;

	public WebhookPayload $payload;
}
