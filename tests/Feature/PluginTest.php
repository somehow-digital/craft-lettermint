<?php

declare(strict_types=1);

use craft\helpers\MailerHelper;
use craft\helpers\StringHelper;
use craft\mail\Mailer;
use craft\mail\transportadapters\Sendmail;
use craft\models\Site;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\base\LettermintApiInterface;
use SomehowDigital\Craft\Lettermint\enums\CheckStatus;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\services\ChecksService;
use SomehowDigital\Craft\Lettermint\services\HealthService;
use SomehowDigital\Craft\Lettermint\services\MailSettingsService;
use SomehowDigital\Craft\Lettermint\services\UtilityService;
use SomehowDigital\Craft\Lettermint\services\WebhooksService;
use SomehowDigital\Craft\Lettermint\Tests\Support\FakeLettermintApi;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;
use yii\base\InvalidConfigException;

it('provides standard and pro editions', function (): void {
	expect(Plugin::editions())->toBe([Edition::Standard->value, Edition::Pro->value]);
});

it('defaults to the standard edition', function (): void {
	$plugin = plugin();

	expect($plugin->edition)->toBe(Edition::Standard->value);
	expect($plugin->is(Edition::Standard->value))->toBeTrue();
	expect($plugin->is(Edition::Pro->value, '>='))->toBeFalse();
});

it('detects the pro edition', function (): void {
	$plugin = plugin();
	$plugin->edition = Edition::Pro->value;

	expect($plugin->is(Edition::Pro->value))->toBeTrue();
	expect($plugin->is(Edition::Standard->value, '>'))->toBeTrue();
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

	// The transport creates its own HTTP client, which is swapped for one answering with a mocked response.
	(new ReflectionProperty(AbstractHttpTransport::class, 'client'))->setValue($transport, $client);

	// Craft's mailer renders messages for the current site.
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
	expect(plugin()->getChecks())->toBeInstanceOf(ChecksService::class)->toBe(plugin()->checks);
	expect(plugin()->getHealth())->toBeInstanceOf(HealthService::class)->toBe(plugin()->health);
	expect(plugin()->getMailSettings())->toBeInstanceOf(MailSettingsService::class)->toBe(plugin()->mailSettings);
	expect(plugin()->getUtility())->toBeInstanceOf(UtilityService::class)->toBe(plugin()->utility);
	expect(plugin()->getWebhooks())->toBeInstanceOf(WebhooksService::class)->toBe(plugin()->webhooks);
});

it('reads the team API for the health checks and the utility through the API bound in the container', function (): void {
	edition(Edition::Pro);
	signIn();
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);
	Craft::$container->set(LettermintApiInterface::class, $api = new FakeLettermintApi());

	$report = plugin()->getHealth()->check(assertInstance(plugin()->getMailSettings()->settings(), Settings::class));
	plugin()->getUtility()->data();

	expect($api->loads)->toBe([false, true]);
	expect($report->usage)->toBeNull();
});

it('checks the project token through the API bound in the container', function (): void {
	edition(Edition::Pro);
	signIn();
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);
	Craft::$container->set(LettermintApiInterface::class, $api = new FakeLettermintApi(TokenStatus::Invalid));

	$report = plugin()->getHealth()->check(assertInstance(plugin()->getMailSettings()->settings(), Settings::class));
	$data = plugin()->getUtility()->data();

	expect($api->tokens)->toBe(['secret', 'secret']);
	expect($report->checks[0]->status)->toBe(CheckStatus::Error);
	expect($data->token?->status)->toBe(CheckStatus::Error);
});
