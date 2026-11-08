<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\base\WebhookVerifierInterface;
use SomehowDigital\Craft\Lettermint\errors\WebhookVerificationException;
use SomehowDigital\Craft\Lettermint\events\WebhookEvent;
use SomehowDigital\Craft\Lettermint\models\WebhookPayload;
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
final class WebhooksService extends Component
{
	/**
	 * Fired for every delivery.
	 */
	public const EVENT_RECEIVE_WEBHOOK = 'receiveWebhook';

	/**
	 * The types of deliveries that Lettermint sends.
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
	 * The plugin binds the verifier of the deliveries in the container.
	 *
	 * @internal
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly WebhookVerifierInterface $verifier,
		array $config = [],
	) {
		// Yii configures the component, which takes only its event handlers and behaviors from its config here.
		parent::__construct($config); // @pest-mutate-ignore: RemoveMethodCall
	}

	/**
	 * Verifies a delivery with the signing secret of the webhook and fires its event.
	 *
	 * @internal
	 * @param array<mixed> $headers The headers of the request.
	 * @throws WebhookVerificationException when the delivery is not genuine.
	 */
	public function receive(#[SensitiveParameter] string $secret, string $body, array $headers): void
	{
		$this->dispatch($this->verifier->verify($secret, $body, $headers));
	}

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
