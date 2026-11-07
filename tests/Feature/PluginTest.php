<?php

declare(strict_types=1);

use craft\helpers\MailerHelper;
use craft\helpers\StringHelper;
use craft\mail\Mailer;
use craft\mail\transportadapters\Sendmail;
use craft\models\Site;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\ChecksService;
use SomehowDigital\Craft\Lettermint\CheckStatus;
use SomehowDigital\Craft\Lettermint\Edition;
use SomehowDigital\Craft\Lettermint\HealthService;
use SomehowDigital\Craft\Lettermint\MailSettings;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\Settings;
use SomehowDigital\Craft\Lettermint\TeamApi;
use SomehowDigital\Craft\Lettermint\TeamService;
use SomehowDigital\Craft\Lettermint\TokenStatus;
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
	expect(plugin()->getTeam())->toBeInstanceOf(TeamService::class)->toBe(plugin()->team);
});

it('reads the team API through the checks component, which projects can replace', function (): void {
	plugin()->edition = Edition::Pro->value;
	signIn();
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$this->http(healthResponses());

	$checks = new class () extends ChecksService {
		/** @var list<string> */
		public array $tokens = [];

		public function api(#[SensitiveParameter] string $token): TeamApi
		{
			$this->tokens[] = $token;

			return parent::api($token);
		}
	};

	plugin()->set('checks', $checks);

	plugin()->getHealth()->check(assertInstance(MailSettings::settings(), Settings::class));
	plugin()->getTeam()->data();

	expect($checks->tokens)->toBe(['team-secret', 'team-secret']);
});

it('checks the project token through the checks component, which projects can replace', function (): void {
	plugin()->edition = Edition::Pro->value;
	signIn();
	healthEmail(['teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$this->http(healthResponses());

	$checks = new class () extends ChecksService {
		/** @var list<?string> */
		public array $tokens = [];

		public function tokenStatus(#[SensitiveParameter] ?string $token): TokenStatus
		{
			$this->tokens[] = $token;

			return TokenStatus::Invalid;
		}
	};

	plugin()->set('checks', $checks);

	$report = plugin()->getHealth()->check(assertInstance(MailSettings::settings(), Settings::class));
	$data = plugin()->getTeam()->data();

	expect($checks->tokens)->toBe(['secret', 'secret']);
	expect($report->checks[0]->status)->toBe(CheckStatus::Error);
	expect($data->token?->status)->toBe(CheckStatus::Error);
});
