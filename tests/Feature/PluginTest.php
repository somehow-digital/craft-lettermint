<?php

declare(strict_types=1);

use craft\helpers\MailerHelper;
use craft\helpers\StringHelper;
use craft\mail\Mailer;
use craft\mail\transportadapters\Sendmail;
use craft\models\Site;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\services\StatusService;
use SomehowDigital\Craft\Lettermint\services\WebhooksService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;
use yii\base\InvalidConfigException;

it('provides lite and pro editions', function (): void {
	expect(Plugin::editions())->toBe([Edition::Lite->value, Edition::Pro->value]);
});

it('defaults to the lite edition', function (): void {
	$plugin = plugin();

	expect($plugin->edition)->toBe(Edition::Lite->value);
	expect($plugin->is(Edition::Lite->value))->toBeTrue();
	expect($plugin->is(Edition::Pro->value, '>='))->toBeFalse();
});

it('detects the pro edition', function (): void {
	$plugin = plugin();
	$plugin->edition = Edition::Pro->value;

	expect($plugin->is(Edition::Pro->value))->toBeTrue();
	expect($plugin->is(Edition::Lite->value, '>'))->toBeTrue();
});

it('registers the adapter as a mailer transport', function (): void {
	expect(MailerHelper::allMailerTransportTypes())->toContain(Adapter::class);
});

it('keeps the transports of Craft', function (): void {
	expect(MailerHelper::allMailerTransportTypes())->toContain(Sendmail::class);
});

it('creates the adapter from mail settings', function (): void {
	$adapter = MailerHelper::createTransportAdapter(Adapter::class, [
		'token' => 'secret',
		'route' => 'outgoing',
	]);

	expect($adapter)->toBeInstanceOf(Adapter::class);
	expect($adapter->token)->toBe('secret');
	expect($adapter->route)->toBe('outgoing');
});

it('sends emails through the Lettermint API', function (): void {
	$requests = [];

	$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): JsonMockResponse {
		$requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

		return new JsonMockResponse(['message_id' => 'message-123'], ['http_code' => 202]);
	});

	$this->env(['LETTERMINT_TOKEN' => 'secret']);

	$adapter = MailerHelper::createTransportAdapter(Adapter::class, [
		'token' => '$LETTERMINT_TOKEN',
		'route' => 'outgoing',
	]);

	/** @var AbstractHttpTransport $transport */
	$transport = $adapter->defineTransport();

	// Swaps the transport's own HTTP client for a mock.
	(new ReflectionProperty(AbstractHttpTransport::class, 'client'))->setValue($transport, $client);

	Craft::$app->getSites()->setCurrentSite(new Site(['uid' => StringHelper::UUID(), 'language' => 'en-US']));

	$mailer = new Mailer(['transport' => $transport]);

	$sent = $mailer->compose()
		->setFrom(SENDER_EMAIL)
		->setTo(RECIPIENT_EMAIL)
		->setSubject('Hello')
		->setTextBody('Hello there')
		->send();

	expect($sent)->toBeTrue();
	expect($requests)->toHaveCount(1);
	expect($requests[0]['method'])->toBe('POST');
	expect($requests[0]['url'])->toBe('https://api.lettermint.co/v1/send');
	expect(assertArray($requests[0]['options']['normalized_headers'])['x-lettermint-token'])->toBe(['x-lettermint-token: secret']);
	expect(jsonArray($requests[0]['options']['body']))->toMatchArray([
		'subject' => 'Hello',
		'from' => SENDER_EMAIL,
		'to' => [RECIPIENT_EMAIL],
		'text' => 'Hello there',
		'route' => 'outgoing',
	]);
});

it('is not pro without the plugin', function (): void {
	Plugin::setInstance(null);

	expect(Plugin::isPro())->toBeFalse();
});

it('has no instance without the plugin', function (): void {
	Plugin::setInstance(null);

	Plugin::instance();
})->throws(InvalidConfigException::class, 'The Lettermint plugin is not loaded.');

it('registers its components', function (): void {
	expect(plugin()->getStatus())->toBeInstanceOf(StatusService::class)->toBe(plugin()->status);
	expect(plugin()->getWebhooks())->toBeInstanceOf(WebhooksService::class)->toBe(plugin()->webhooks);
});

it('configures the status with the version of the plugin', function (): void {
	expect(plugin()->getStatus()->version)->toBe(plugin()->getVersion());
});
