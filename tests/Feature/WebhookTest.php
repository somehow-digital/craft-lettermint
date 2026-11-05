<?php

use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\events\WebhookEvent;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\Tests\Support\HandlerException;
use SomehowDigital\Craft\Lettermint\Webhooks;
use yii\base\Event;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;

const WEBHOOK_SECRET = 'whsec_test';

/**
 * Sets the email settings, with the adapter as the transport.
 */
function webhookEmail(array $settings): void
{
	Craft::$app->getProjectConfig()->set('email', [
		'fromEmail' => 'hello@acme.test',
		'transportType' => Adapter::class,
		'transportSettings' => ['token' => 'secret', ...$settings],
	]);
}

/**
 * Posts a delivery to the endpoint, signed with the secret, and returns the response.
 *
 * @param array<string,string>|null $headers The headers of the delivery instead of a valid signature.
 */
function deliver(array|string $payload, string $secret = WEBHOOK_SECRET, ?array $headers = null, string $method = 'POST'): yii\web\Response
{
	$body = is_string($payload) ? $payload : json_encode($payload);
	$timestamp = (string) time();

	$headers ??= [
		'X-Lettermint-Signature' => sprintf('t=%s,v1=%s', $timestamp, hash_hmac('sha256', "{$timestamp}.{$body}", $secret)),
		'X-Lettermint-Delivery' => $timestamp,
	];

	$_SERVER['REQUEST_METHOD'] = $method;

	$request = Craft::$app->getRequest();
	$request->setRawBody($body);

	foreach ($headers as $name => $value) {
		$request->getHeaders()->set($name, $value);
	}

	try {
		Craft::$app->runAction(Webhooks::ACTION);
	} finally {
		unset($_SERVER['REQUEST_METHOD']);
	}

	return Craft::$app->getResponse();
}

/**
 * Records the events fired for the deliveries.
 *
 * @return ArrayObject<int,WebhookEvent>
 */
function recordWebhookEvents(): ArrayObject
{
	$events = new ArrayObject();

	Event::on(Webhooks::class, Webhooks::EVENT_RECEIVE_WEBHOOK, static function (WebhookEvent $event) use ($events) {
		$events[] = $event;
	});

	return $events;
}

const DELIVERED = [
	'id' => 'delivery-1',
	'event' => 'message.delivered',
	'timestamp' => '2026-11-04T10:00:00Z',
	'data' => ['message_id' => 'message-1', 'recipient' => 'recipient@example.test'],
];

beforeEach(function () {
	Plugin::getInstance()->edition = Plugin::EDITION_PRO;
});

it('has an endpoint on the primary site', function () {
	expect(Webhooks::url())
		->toStartWith('https://example.test/')
		->toEndWith('actions/lettermint/webhook');
});

it('fires one event for a delivery, with its type and payload', function () {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$events = recordWebhookEvents();

	$response = deliver(DELIVERED);

	expect($response->getStatusCode())->toBe(200);
	expect($events)->toHaveCount(1);

	$event = $events[0];

	expect($event->name)->toBe('receiveWebhook');
	expect($event->type)->toBe(Webhooks::TYPE_MESSAGE_DELIVERED);
	expect($event->payload->id)->toBe('delivery-1');
	expect($event->payload->timestamp)->toBe('2026-11-04T10:00:00Z');
	expect($event->payload->data)->toBe(DELIVERED['data']);
});

it('fires the event for types that are not known yet', function () {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$events = recordWebhookEvents();

	deliver(['event' => 'message.teleported', 'data' => []]);

	expect($events)->toHaveCount(1);
	expect($events[0]->type)->toBe('message.teleported');
});

it('fires the event for the test delivery of Lettermint', function () {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$events = recordWebhookEvents();

	deliver(['event' => 'webhook.test', 'data' => []]);

	expect($events[0]->type)->toBe(Webhooks::TYPE_WEBHOOK_TEST);
});

it('has the types of the SDK', function () {
	$types = array_filter((new ReflectionClass(Webhooks::class))->getConstants(), static fn (string $name) => str_starts_with($name, 'TYPE_'), ARRAY_FILTER_USE_KEY);

	expect(array_values($types))->toEqualCanonicalizing(Lettermint\Types\WebhookEvent::VALUES);
});

it('resolves the secret from an environment variable', function () {
	$this->env(['LETTERMINT_WEBHOOK_SECRET' => WEBHOOK_SECRET]);
	webhookEmail(['webhookSecret' => '$LETTERMINT_WEBHOOK_SECRET']);
	$events = recordWebhookEvents();

	expect(deliver(DELIVERED)->getStatusCode())->toBe(200);
	expect($events)->toHaveCount(1);
});

it('rejects deliveries signed with another secret', function () {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$events = recordWebhookEvents();

	expect(fn () => deliver(DELIVERED, 'whsec_other'))->toThrow(UnauthorizedHttpException::class);
	expect($events)->toHaveCount(0);
});

it('rejects deliveries without a signature', function () {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$events = recordWebhookEvents();

	expect(fn () => deliver(DELIVERED, headers: []))->toThrow(UnauthorizedHttpException::class);
	expect($events)->toHaveCount(0);
});

it('rejects deliveries signed too long ago', function () {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$body = json_encode(DELIVERED);
	$timestamp = (string) (time() - 3600);

	expect(fn () => deliver($body, headers: [
		'X-Lettermint-Signature' => sprintf('t=%s,v1=%s', $timestamp, hash_hmac('sha256', "{$timestamp}.{$body}", WEBHOOK_SECRET)),
		'X-Lettermint-Delivery' => $timestamp,
	]))->toThrow(UnauthorizedHttpException::class);
});

it('only accepts posted deliveries', function () {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);

	expect(fn () => deliver(DELIVERED, method: 'GET'))->toThrow(MethodNotAllowedHttpException::class);
});

it('fails the delivery when a handler fails, so Lettermint delivers it again', function () {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	Event::on(Webhooks::class, Webhooks::EVENT_RECEIVE_WEBHOOK, static function () {
		throw new HandlerException('Handler failed');
	});

	expect(fn () => deliver(DELIVERED))->toThrow(HandlerException::class, 'Handler failed');
});

it('has no endpoint in the standard edition', function () {
	Plugin::getInstance()->edition = Plugin::EDITION_STANDARD;
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$events = recordWebhookEvents();

	expect(fn () => deliver(DELIVERED))->toThrow(NotFoundHttpException::class);
	expect($events)->toHaveCount(0);
});

it('has no endpoint without a secret', function (?string $secret) {
	webhookEmail(['webhookSecret' => $secret]);

	expect(fn () => deliver(DELIVERED))->toThrow(NotFoundHttpException::class);
})->with([
	'none' => [null],
	'empty' => [''],
	'missing environment variable' => ['$LETTERMINT_MISSING'],
]);

it('has no endpoint when Lettermint is not the transport', function () {
	expect(fn () => deliver(DELIVERED))->toThrow(NotFoundHttpException::class);
});
