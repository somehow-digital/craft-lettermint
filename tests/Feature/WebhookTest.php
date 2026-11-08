<?php

declare(strict_types=1);

use Lettermint\Exceptions\WebhookVerificationException;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\events\WebhookEvent;
use SomehowDigital\Craft\Lettermint\services\WebhooksService;
use SomehowDigital\Craft\Lettermint\Tests\Support\HandlerException;
use yii\base\Event;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

const WEBHOOK_SECRET = 'whsec_test';

/**
 * @param array<string,mixed> $settings
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
 * Posts a signed delivery.
 *
 * @param array<mixed>|string $payload
 * @param array<string,string>|null $headers The headers of the delivery instead of a valid signature.
 */
function deliver(array|string $payload, string $secret = WEBHOOK_SECRET, ?array $headers = null, string $method = 'POST'): Response
{
	$body = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);
	$timestamp = (string) time();

	$headers ??= [
		'X-Lettermint-Signature' => sprintf('t=%s,v1=%s', $timestamp, hash_hmac('sha256', "{$timestamp}.{$body}", $secret)),
		'X-Lettermint-Delivery' => $timestamp,
	];

	$_SERVER['REQUEST_METHOD'] = $method;

	$request = webRequest();
	$request->setRawBody($body);

	foreach ($headers as $name => $value) {
		$request->getHeaders()->set($name, $value);
	}

	try {
		Craft::$app->runAction('lettermint/webhook');
	} finally {
		unset($_SERVER['REQUEST_METHOD']);
	}

	return webResponse();
}

/**
 * @return ArrayObject<int,WebhookEvent>
 */
function recordWebhookEvents(): ArrayObject
{
	/** @var ArrayObject<int,WebhookEvent> $events */
	$events = new ArrayObject();

	Event::on(WebhooksService::class, WebhooksService::EVENT_RECEIVE_WEBHOOK, static function (WebhookEvent $event) use ($events): void {
		$events[] = $event;
	});

	return $events;
}

const DELIVERED = [
	'id' => 'delivery-1',
	'event' => 'message.delivered',
	'timestamp' => '2026-11-04T10:00:00Z',
	'data' => ['message_id' => 'message-1', 'recipient' => RECIPIENT_EMAIL],
];

beforeEach(function (): void {
	edition(Edition::Pro);
});

it('fires one event for a delivery, with its type and payload', function (): void {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$events = recordWebhookEvents();

	$response = deliver(DELIVERED);

	expect($response->getStatusCode())->toBe(200);
	expect($events)->toHaveCount(1);

	$event = $events->getArrayCopy()[0];

	expect($event->name)->toBe('receiveWebhook');
	expect($event->type)->toBe(WebhooksService::TYPE_MESSAGE_DELIVERED);
	expect($event->payload->id)->toBe('delivery-1');
	expect($event->payload->timestamp)->toBe('2026-11-04T10:00:00Z');
	expect($event->payload->data)->toBe(DELIVERED['data']);
});

it('fires the event for types that are not known yet', function (): void {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$events = recordWebhookEvents();

	deliver(['event' => 'message.teleported', 'data' => []]);

	expect($events)->toHaveCount(1);
	expect($events->getArrayCopy()[0]->type)->toBe('message.teleported');
});

it('fires the event for the test delivery of Lettermint', function (): void {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$events = recordWebhookEvents();

	deliver(['event' => 'webhook.test', 'data' => []]);

	expect($events->getArrayCopy()[0]->type)->toBe(WebhooksService::TYPE_WEBHOOK_TEST);
});

it('has the types of the SDK', function (): void {
	$types = array_filter((new ReflectionClass(WebhooksService::class))->getConstants(), static fn(string $name): bool => str_starts_with($name, 'TYPE_'), ARRAY_FILTER_USE_KEY);

	expect(array_values($types))->toEqualCanonicalizing(Lettermint\Types\WebhookEvent::VALUES);
});

it('resolves the secret from an environment variable', function (): void {
	$this->env(['LETTERMINT_WEBHOOK_SECRET' => WEBHOOK_SECRET]);
	webhookEmail(['webhookSecret' => '$LETTERMINT_WEBHOOK_SECRET']);
	$events = recordWebhookEvents();

	expect(deliver(DELIVERED)->getStatusCode())->toBe(200);
	expect($events)->toHaveCount(1);
});

it('answers a delivery with an empty body', function (): void {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);

	$response = deliver(DELIVERED);

	expect($response->getStatusCode())->toBe(200);
	expect($response->data)->toBe('');
});

it('logs rejected deliveries', function (): void {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);

	expect(fn(): Response => deliver(DELIVERED, headers: []))->toThrow(UnauthorizedHttpException::class);
	expect(loggedWarnings())->toHaveCount(1);
	expect(loggedWarnings()[0])->toStartWith('Rejected a Lettermint webhook delivery: ');
});

it('rejects deliveries signed with another secret', function (): void {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$events = recordWebhookEvents();

	expect(fn(): Response => deliver(DELIVERED, 'whsec_other'))->toThrow(UnauthorizedHttpException::class);
	expect($events)->toHaveCount(0);
});

it('rejects deliveries without a signature', function (): void {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$events = recordWebhookEvents();

	expect(fn(): Response => deliver(DELIVERED, headers: []))->toThrow(UnauthorizedHttpException::class);
	expect($events)->toHaveCount(0);
});

it('rejects deliveries signed too long ago', function (): void {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$body = json_encode(DELIVERED, JSON_THROW_ON_ERROR);
	$timestamp = (string) (time() - 3600);

	expect(fn(): Response => deliver($body, headers: [
		'X-Lettermint-Signature' => sprintf('t=%s,v1=%s', $timestamp, hash_hmac('sha256', "{$timestamp}.{$body}", WEBHOOK_SECRET)),
		'X-Lettermint-Delivery' => $timestamp,
	]))->toThrow(UnauthorizedHttpException::class);
});

it('only accepts posted deliveries', function (): void {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);

	expect(fn(): Response => deliver(DELIVERED, method: 'GET'))->toThrow(MethodNotAllowedHttpException::class);
});

it('fails the delivery when a handler fails, so Lettermint delivers it again', function (): void {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	Event::on(WebhooksService::class, WebhooksService::EVENT_RECEIVE_WEBHOOK, static function (): never {
		throw new HandlerException('Handler failed');
	});

	expect(fn(): Response => deliver(DELIVERED))->toThrow(HandlerException::class, 'Handler failed');
});

it('fails the delivery for any exception of a handler, also one of a failed verification', function (): void {
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	Event::on(WebhooksService::class, WebhooksService::EVENT_RECEIVE_WEBHOOK, static function (): never {
		throw new WebhookVerificationException(WebhookVerificationException::SIGNATURE_MISMATCH, 'Handler failed');
	});

	// Lettermint doesn't retry after a 401.
	expect(fn(): Response => deliver(DELIVERED))->toThrow(WebhookVerificationException::class, 'Handler failed');
});

it('has no endpoint in the standard edition', function (): void {
	edition(Edition::Standard);
	webhookEmail(['webhookSecret' => WEBHOOK_SECRET]);
	$events = recordWebhookEvents();

	expect(fn(): Response => deliver(DELIVERED))->toThrow(NotFoundHttpException::class);
	expect($events)->toHaveCount(0);
});

it('has no endpoint without a secret', function (?string $secret): void {
	webhookEmail(['webhookSecret' => $secret]);

	expect(fn(): Response => deliver(DELIVERED))->toThrow(NotFoundHttpException::class);
})->with([
	'none' => [null],
	'empty' => [''],
	'missing environment variable' => ['$LETTERMINT_MISSING'],
]);

it('has no endpoint when Lettermint is not the transport', function (): void {
	expect(fn(): Response => deliver(DELIVERED))->toThrow(NotFoundHttpException::class);
});
