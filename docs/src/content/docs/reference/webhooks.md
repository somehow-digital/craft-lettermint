---
title: Webhooks
description: The event types, responses and retries of the webhook endpoint.
sidebar:
  badge:
    text: Pro
    variant: tip
---

## Event types

Each event type is a constant of `SomehowDigital\Craft\Lettermint\services\WebhooksService`. New types from Lettermint fire the event too, so compare against the constant or the raw string. See [webhooks](/features/webhooks/).

| Constant                       | Type                      |
| ------------------------------ | ------------------------- |
| `TYPE_MESSAGE_CREATED`         | `message.created`         |
| `TYPE_MESSAGE_SENT`            | `message.sent`            |
| `TYPE_MESSAGE_DELIVERED`       | `message.delivered`       |
| `TYPE_MESSAGE_AUTO_REPLIED`    | `message.auto_replied`    |
| `TYPE_MESSAGE_HARD_BOUNCED`    | `message.hard_bounced`    |
| `TYPE_MESSAGE_SOFT_BOUNCED`    | `message.soft_bounced`    |
| `TYPE_MESSAGE_SPAM_COMPLAINT`  | `message.spam_complaint`  |
| `TYPE_MESSAGE_FAILED`          | `message.failed`          |
| `TYPE_MESSAGE_SUPPRESSED`      | `message.suppressed`      |
| `TYPE_MESSAGE_UNSUBSCRIBED`    | `message.unsubscribed`    |
| `TYPE_MESSAGE_OPENED`          | `message.opened`          |
| `TYPE_MESSAGE_CLICKED`         | `message.clicked`         |
| `TYPE_MESSAGE_INBOUND`         | `message.inbound`         |
| `TYPE_MESSAGE_POLICY_REJECTED` | `message.policy_rejected` |
| `TYPE_MESSAGE_SCHEDULED`       | `message.scheduled`       |
| `TYPE_MESSAGE_RESCHEDULED`     | `message.rescheduled`     |
| `TYPE_MESSAGE_CANCELED`        | `message.canceled`        |
| `TYPE_MESSAGE_RELEASED`        | `message.released`        |
| `TYPE_SUPPRESSION_ADDED`       | `suppression.added`       |
| `TYPE_SUPPRESSION_REMOVED`     | `suppression.removed`     |
| `TYPE_INBOUND_REJECTED`        | `inbound.rejected`        |
| `TYPE_WEBHOOK_TEST`            | `webhook.test`            |

Test deliveries from the Lettermint dashboard use `TYPE_WEBHOOK_TEST`.

## Responses and retries

| Situation                                 | Response | Lettermint retries?      |
| ----------------------------------------- | :------: | ------------------------ |
| Valid signature, handlers succeed         |  `200`   |                          |
| Invalid signature or older than 5 minutes |  `401`   | No                       |
| A handler throws an exception             |  `500`   | Yes, for up to ~14 hours |
| No response within 30 seconds             |          | Yes                      |

Webhooks are also received while the system is offline.
