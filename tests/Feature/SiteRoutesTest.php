<?php

declare(strict_types=1);

use craft\models\Site;
use SomehowDigital\Craft\Lettermint\Edition;
use SomehowDigital\Craft\Lettermint\Settings;
use SomehowDigital\Craft\Lettermint\SiteOptions;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use yii\symfonymailer\Message;

beforeEach(function (): void {
	plugin()->edition = Edition::Pro->value;
});

it('sends emails with the route of their site', function (): void {
	$payload = sendForSite([
		'route' => 'outgoing',
		'sites' => [
			'site-a' => ['route' => 'shop-a'],
			'site-b' => ['route' => 'shop-b'],
		],
	], 'site-b');

	expect($payload['route'])->toBe('shop-b');
});

it('falls back to the route of the adapter for sites without a route', function (): void {
	$payload = sendForSite([
		'route' => 'outgoing',
		'sites' => ['site-a' => ['route' => 'shop-a']],
	], 'site-b');

	expect($payload['route'])->toBe('outgoing');
});

it('ignores the site routes in the standard edition', function (): void {
	plugin()->edition = Edition::Standard->value;

	$payload = sendForSite([
		'route' => 'outgoing',
		'sites' => ['site-a' => ['route' => 'shop-a']],
	], 'site-a');

	expect($payload['route'])->toBe('outgoing');
});

it('prefers a route set on the message', function (): void {
	$payload = sendForSite([
		'sites' => ['site-a' => ['route' => 'shop-a']],
	], 'site-a', function (Message $message): void {
		$message->setHeader('X-LM-Options', json_encode(['route' => 'custom'], JSON_THROW_ON_ERROR));
	});

	expect($payload['route'])->toBe('custom');
});

it('keeps the other options set on the message', function (): void {
	$payload = sendForSite([
		'sites' => ['site-a' => ['route' => 'shop-a']],
	], 'site-a', function (Message $message): void {
		$message->setHeader('X-LM-Options', json_encode(['settings' => ['track_clicks' => false]], JSON_THROW_ON_ERROR));
	});

	expect($payload)->toMatchArray([
		'route' => 'shop-a',
		'settings' => ['track_clicks' => false],
	]);
});

it('leaves invalid options set on the message to the transport', function (): void {
	expect(fn(): array => sendForSite([
		'sites' => ['site-a' => ['route' => 'shop-a']],
	], 'site-a', function (Message $message): void {
		$message->setHeader('X-LM-Options', 'invalid');
	}))->toThrow(InvalidArgumentException::class, 'X-LM-Options must contain a JSON object.');
});

it('sends emails with the tags of their site added to the tags of the adapter', function (): void {
	$payload = sendForSite([
		'tags' => 'environment=production, site=default',
		'sites' => ['site-a' => ['tags' => 'site=a, shop=main']],
	], 'site-a');

	expect($payload)->not->toHaveKey('route');
	expect($payload['tags'])->toBe([
		['name' => 'environment', 'value' => 'production'],
		['name' => 'site', 'value' => 'a'],
		['name' => 'shop', 'value' => 'main'],
	]);
});

it('sends emails with the tags of the adapter for sites without tags', function (): void {
	$payload = sendForSite([
		'tags' => 'environment=production',
		'sites' => ['site-a' => ['route' => 'shop-a']],
	], 'site-a');

	expect($payload['route'])->toBe('shop-a');
	expect($payload['tags'])->toBe([['name' => 'environment', 'value' => 'production']]);
});

it('ignores the site tags in the standard edition', function (): void {
	plugin()->edition = Edition::Standard->value;

	$payload = sendForSite([
		'tags' => 'environment=production',
		'sites' => ['site-a' => ['tags' => 'site=a']],
	], 'site-a');

	expect($payload['tags'])->toBe([['name' => 'environment', 'value' => 'production']]);
});

it('prefers tags set on the message', function (): void {
	$payload = sendForSite([
		'sites' => ['site-a' => ['tags' => 'site=a']],
	], 'site-a', function (Message $message): void {
		$message->setHeader('X-LM-Options', json_encode(['tags' => [['name' => 'custom', 'value' => 'yes']]], JSON_THROW_ON_ERROR));
	});

	expect($payload['tags'])->toBe([['name' => 'custom', 'value' => 'yes']]);
});

it('skips messages that are not emails', function (): void {
	$message = new RawMessage('Hello');
	// The site has options, which an email would get.
	Craft::$app->getSites()->setCurrentSite(new Site(['uid' => 'site-a', 'language' => 'en-US']));

	(new SiteOptions(new Settings(sites: ['site-a' => ['route' => 'shop-a', 'tags' => []]])))(new MessageEvent($message, new Envelope(new Address(SENDER_EMAIL), [new Address(RECIPIENT_EMAIL)]), 'lettermint'));

	expect($message->toString())->toBe('Hello');
});

it('sends emails with the route of the adapter when there is no site', function (): void {
	$payload = sendThroughTransport([
		'route' => 'outgoing',
		'sites' => ['site-a' => ['route' => 'shop-a']],
	]);

	expect($payload['route'])->toBe('outgoing');
});
