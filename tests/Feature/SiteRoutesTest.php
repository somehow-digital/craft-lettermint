<?php

use craft\helpers\MailerHelper;
use craft\mail\Mailer;
use craft\models\Site;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Plugin;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;
use yii\symfonymailer\Message;

/**
 * Sends a message for a site through the adapter, returning the payload sent to the Lettermint API.
 *
 * @param array<string,mixed> $settings
 * @param ?callable(Message):void $prepare
 * @return array<string,mixed>
 */
function sendForSite(array $settings, string $siteUid, ?callable $prepare = null): array
{
	$payload = null;

	$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$payload) {
		$payload = json_decode($options['body'], true);

		return new JsonMockResponse(['message_id' => 'message-123'], ['http_code' => 202]);
	});

	$adapter = MailerHelper::createTransportAdapter(Adapter::class, ['token' => 'secret'] + $settings);

	/** @var AbstractHttpTransport $transport */
	$transport = $adapter->defineTransport();

	// The transport creates its own HTTP client, which is swapped for one answering with a mocked response.
	(new ReflectionProperty(AbstractHttpTransport::class, 'client'))->setValue($transport, $client);

	// Craft sets the site of the message as the current site while sending.
	Craft::$app->getSites()->setCurrentSite(new Site(['uid' => $siteUid, 'language' => 'en-US']));

	$message = (new Mailer(['transport' => $transport]))->compose()
		->setFrom('sender@example.test')
		->setTo('recipient@example.test')
		->setSubject('Hello')
		->setTextBody('Hello there');

	if ($prepare) {
		$prepare($message);
	}

	expect($message->send())->toBeTrue();

	return $payload;
}

beforeEach(function () {
	Plugin::getInstance()->edition = Plugin::EDITION_PRO;
});

it('sends emails with the route of their site', function () {
	$payload = sendForSite([
		'route' => 'outgoing',
		'sites' => [
			'site-a' => ['route' => 'shop-a'],
			'site-b' => ['route' => 'shop-b'],
		],
	], 'site-b');

	expect($payload['route'])->toBe('shop-b');
});

it('falls back to the route of the adapter for sites without a route', function () {
	$payload = sendForSite([
		'route' => 'outgoing',
		'sites' => ['site-a' => ['route' => 'shop-a']],
	], 'site-b');

	expect($payload['route'])->toBe('outgoing');
});

it('ignores the site routes in the standard edition', function () {
	Plugin::getInstance()->edition = Plugin::EDITION_STANDARD;

	$payload = sendForSite([
		'route' => 'outgoing',
		'sites' => ['site-a' => ['route' => 'shop-a']],
	], 'site-a');

	expect($payload['route'])->toBe('outgoing');
});

it('prefers a route set on the message', function () {
	$payload = sendForSite([
		'sites' => ['site-a' => ['route' => 'shop-a']],
	], 'site-a', function (Message $message) {
		$message->setHeader('X-LM-Options', json_encode(['route' => 'custom']));
	});

	expect($payload['route'])->toBe('custom');
});

it('keeps the other options set on the message', function () {
	$payload = sendForSite([
		'sites' => ['site-a' => ['route' => 'shop-a']],
	], 'site-a', function (Message $message) {
		$message->setHeader('X-LM-Options', json_encode(['settings' => ['track_clicks' => false]]));
	});

	expect($payload)->toMatchArray([
		'route' => 'shop-a',
		'settings' => ['track_clicks' => false],
	]);
});
