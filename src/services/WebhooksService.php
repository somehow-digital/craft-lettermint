<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use Lettermint\Types\WebhookEvent as Events;
use Lettermint\WebhookPayload;
use SomehowDigital\Craft\Lettermint\events\WebhookEvent;
use yii\base\Component;

/**
 * Fires an event for each verified Lettermint webhook delivery (Pro edition).
 *
 * Every delivery fires `EVENT_RECEIVE_WEBHOOK`, with the Lettermint event as its type, which handlers compare with
 * the `TYPE_*` constants. Types Lettermint adds later fire the event as well.
 *
 * ```php
 * Event::on(WebhooksService::class, WebhooksService::EVENT_RECEIVE_WEBHOOK, function (WebhookEvent $event) {
 *     if ($event->type === WebhooksService::TYPE_MESSAGE_HARD_BOUNCED) {
 *         $recipient = $event->payload->data['recipient'] ?? null;
 *     }
 * });
 * ```
 *
 * An exception thrown by a handler fails the delivery, which Lettermint then delivers again.
 *
 * The plugin registers it as its `webhooks` component.
 */
class WebhooksService extends Component
{
	/**
	 * Fired for every delivery.
	 */
	public const EVENT_RECEIVE_WEBHOOK = 'receiveWebhook';

	/**
	 * The types of deliveries that the SDK of Lettermint knows.
	 */
	public const TYPE_MESSAGE_CREATED = Events::MESSAGE_CREATED;
	public const TYPE_MESSAGE_SENT = Events::MESSAGE_SENT;
	public const TYPE_MESSAGE_DELIVERED = Events::MESSAGE_DELIVERED;
	public const TYPE_MESSAGE_AUTO_REPLIED = Events::MESSAGE_AUTO_REPLIED;
	public const TYPE_MESSAGE_HARD_BOUNCED = Events::MESSAGE_HARD_BOUNCED;
	public const TYPE_MESSAGE_SOFT_BOUNCED = Events::MESSAGE_SOFT_BOUNCED;
	public const TYPE_MESSAGE_SPAM_COMPLAINT = Events::MESSAGE_SPAM_COMPLAINT;
	public const TYPE_MESSAGE_FAILED = Events::MESSAGE_FAILED;
	public const TYPE_MESSAGE_SUPPRESSED = Events::MESSAGE_SUPPRESSED;
	public const TYPE_MESSAGE_UNSUBSCRIBED = Events::MESSAGE_UNSUBSCRIBED;
	public const TYPE_MESSAGE_OPENED = Events::MESSAGE_OPENED;
	public const TYPE_MESSAGE_CLICKED = Events::MESSAGE_CLICKED;
	public const TYPE_MESSAGE_INBOUND = Events::MESSAGE_INBOUND;
	public const TYPE_MESSAGE_POLICY_REJECTED = Events::MESSAGE_POLICY_REJECTED;
	public const TYPE_MESSAGE_SCHEDULED = Events::MESSAGE_SCHEDULED;
	public const TYPE_MESSAGE_RESCHEDULED = Events::MESSAGE_RESCHEDULED;
	public const TYPE_MESSAGE_CANCELED = Events::MESSAGE_CANCELED;
	public const TYPE_MESSAGE_RELEASED = Events::MESSAGE_RELEASED;
	public const TYPE_SUPPRESSION_ADDED = Events::SUPPRESSION_ADDED;
	public const TYPE_SUPPRESSION_REMOVED = Events::SUPPRESSION_REMOVED;
	public const TYPE_INBOUND_REJECTED = Events::INBOUND_REJECTED;
	public const TYPE_WEBHOOK_TEST = Events::WEBHOOK_TEST;

	/**
	 * Fires `EVENT_RECEIVE_WEBHOOK` for a delivery.
	 *
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
