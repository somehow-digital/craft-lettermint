---
title: Webhooks
description: React to deliveries, bounces and complaints with Craft events.
sidebar:
  badge:
    text: Pro
    variant: tip
---

Receive Lettermint [webhook](https://lettermint.co/docs/platform/webhooks/introduction) events (deliveries, bounces, complaints, …) as Craft events.

## Setup

1. In the [Lettermint dashboard](https://app.lettermint.co/), create a webhook and choose its events. Use the endpoint URL shown below the **Webhook Secret** setting, e.g. `https://example.com/actions/lettermint/webhook`.
2. Copy its signing secret (`whsec_…`) into **Webhook Secret**.

## Usage

```php
use SomehowDigital\Craft\Lettermint\events\WebhookEvent;
use SomehowDigital\Craft\Lettermint\services\WebhooksService;
use yii\base\Event;

Event::on(WebhooksService::class, WebhooksService::EVENT_RECEIVE_WEBHOOK, function (WebhookEvent $event) {
    if ($event->type === WebhooksService::TYPE_MESSAGE_HARD_BOUNCED) {
        $event->payload->id;   // delivery ID
        $event->payload->data; // event data, e.g. `recipient`
    }
});
```

All event types are listed in the [reference](/reference/webhooks/#event-types).

:::tip

- The same delivery can arrive more than once. Use `$event->payload->id` to skip duplicates. It stays the same across retries.
- Keep handlers fast. Push slow work to the queue.
- `$event->payload` is a `SomehowDigital\Craft\Lettermint\models\WebhookPayload` with the `id`, `event`, `timestamp` and `data` of the delivery.
  :::

If a handler throws an exception, the endpoint answers `500` and Lettermint retries the delivery. See [responses and retries](/reference/webhooks/#responses-and-retries).
