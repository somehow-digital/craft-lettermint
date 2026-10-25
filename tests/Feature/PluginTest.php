<?php

use craft\helpers\MailerHelper;
use craft\helpers\StringHelper;
use craft\mail\Mailer;
use craft\mail\transportadapters\Sendmail;
use craft\models\Site;
use SomehowDigital\Craft\Lettermint\Adapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;

it('registers the adapter as a mailer transport', function () {
	expect(MailerHelper::allMailerTransportTypes())->toContain(Adapter::class);
});

it('keeps the transports of Craft', function () {
	expect(MailerHelper::allMailerTransportTypes())->toContain(Sendmail::class);
});

it('creates the adapter from mail settings', function () {
	$adapter = MailerHelper::createTransportAdapter(Adapter::class, [
		'token' => 'secret',
		'route' => 'outgoing',
	]);

	expect($adapter)->toBeInstanceOf(Adapter::class);
	expect($adapter->token)->toBe('secret');
	expect($adapter->route)->toBe('outgoing');
});

it('sends emails through the Lettermint API', function () {
	$requests = [];

	$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
		$requests[] = compact('method', 'url', 'options');

		return new JsonMockResponse(['message_id' => 'message-123'], ['http_code' => 202]);
	});

	$this->env(['LETTERMINT_TOKEN' => 'secret']);

	$adapter = MailerHelper::createTransportAdapter(Adapter::class, [
		'token' => '$LETTERMINT_TOKEN',
		'route' => 'outgoing',
	]);

	/** @var AbstractHttpTransport $transport */
	$transport = $adapter->defineTransport();

	// The transport creates its own HTTP client, which is swapped for one answering with a mocked response.
	(new ReflectionProperty(AbstractHttpTransport::class, 'client'))->setValue($transport, $client);

	// Craft's mailer renders messages for the current site.
	Craft::$app->getSites()->setCurrentSite(new Site(['uid' => StringHelper::UUID(), 'language' => 'en-US']));

	$mailer = new Mailer(['transport' => $transport]);

	$sent = $mailer->compose()
		->setFrom('sender@example.test')
		->setTo('recipient@example.test')
		->setSubject('Hello')
		->setTextBody('Hello there')
		->send();

	expect($sent)->toBeTrue();
	expect($requests)->toHaveCount(1);
	expect($requests[0]['method'])->toBe('POST');
	expect($requests[0]['url'])->toBe('https://api.lettermint.co/v1/send');
	expect($requests[0]['options']['normalized_headers']['x-lettermint-token'])->toBe(['x-lettermint-token: secret']);
	expect(json_decode($requests[0]['options']['body'], true))->toMatchArray([
		'subject' => 'Hello',
		'from' => 'sender@example.test',
		'to' => ['recipient@example.test'],
		'text' => 'Hello there',
		'route' => 'outgoing',
	]);
});
