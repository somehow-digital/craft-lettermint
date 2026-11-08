<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use SomehowDigital\Craft\Lettermint\events\WebhookEvent;
use SomehowDigital\Craft\Lettermint\models\WebhookPayload;
use yii\base\Component;

/**
 * Fires `EVENT_RECEIVE_WEBHOOK` for each verified webhook delivery, including types not listed below (Pro edition).
 *
 * ```php
 * Event::on(WebhooksService::class, WebhooksService::EVENT_RECEIVE_WEBHOOK, function (WebhookEvent $event) {
 *     if ($event->type === WebhooksService::TYPE_MESSAGE_HARD_BOUNCED) {
 *         $recipient = $event->payload->data['recipient'] ?? null;
 *     }
 * });
 * ```
 *
 * A handler exception fails the delivery, and Lettermint retries it.
 */
final class WebhooksService extends Component
{
	public const EVENT_RECEIVE_WEBHOOK = 'receiveWebhook';

	/**
	 * Known delivery types.
	 */
	public const TYPE_MESSAGE_CREATED = 'message.created';
	public const TYPE_MESSAGE_SENT = 'message.sent';
	public const TYPE_MESSAGE_DELIVERED = 'message.delivered';
	public const TYPE_MESSAGE_AUTO_REPLIED = 'message.auto_replied';
	public const TYPE_MESSAGE_HARD_BOUNCED = 'message.hard_bounced';
	public const TYPE_MESSAGE_SOFT_BOUNCED = 'message.soft_bounced';
	public const TYPE_MESSAGE_SPAM_COMPLAINT = 'message.spam_complaint';
	public const TYPE_MESSAGE_FAILED = 'message.failed';
	public const TYPE_MESSAGE_SUPPRESSED = 'message.suppressed';
	public const TYPE_MESSAGE_UNSUBSCRIBED = 'message.unsubscribed';
	public const TYPE_MESSAGE_OPENED = 'message.opened';
	public const TYPE_MESSAGE_CLICKED = 'message.clicked';
	public const TYPE_MESSAGE_INBOUND = 'message.inbound';
	public const TYPE_MESSAGE_POLICY_REJECTED = 'message.policy_rejected';
	public const TYPE_MESSAGE_SCHEDULED = 'message.scheduled';
	public const TYPE_MESSAGE_RESCHEDULED = 'message.rescheduled';
	public const TYPE_MESSAGE_CANCELED = 'message.canceled';
	public const TYPE_MESSAGE_RELEASED = 'message.released';
	public const TYPE_SUPPRESSION_ADDED = 'suppression.added';
	public const TYPE_SUPPRESSION_REMOVED = 'suppression.removed';
	public const TYPE_INBOUND_REJECTED = 'inbound.rejected';
	public const TYPE_WEBHOOK_TEST = 'webhook.test';

	/**
	 * @internal
	 */
	public function dispatch(WebhookPayload $payload): void
	{
		if ($this->hasEventHandlers(self::EVENT_RECEIVE_WEBHOOK)) {
			$this->trigger(self::EVENT_RECEIVE_WEBHOOK, new WebhookEvent([
				'type' => $payload->event,
				'payload' => $payload,
			]));
		}
	}
}
