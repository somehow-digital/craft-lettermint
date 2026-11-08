<?php

declare(strict_types=1);

use craft\elements\User;
use craft\helpers\MailerHelper;
use craft\mail\Mailer;
use craft\mail\Message as CraftMessage;
use craft\models\Site;
use craft\web\Request;
use GuzzleHttp\Psr7\Response;
use Pest\TestSuite;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\api\LettermintApi;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\Tests\Support\Sites;
use SomehowDigital\Craft\Lettermint\Tests\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;
use Symfony\Component\Mime\Email;
use yii\log\Logger;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

function jsonResponse(mixed $data, int $status = 200): Response
{
	return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
}

/**
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
function assertInstance(mixed $value, string $class): object
{
	expect($value)->toBeInstanceOf($class);
	assert($value instanceof $class);

	return $value;
}

/**
 * @return array<mixed>
 */
function assertArray(mixed $value): array
{
	expect($value)->toBeArray();
	assert(is_array($value));

	return $value;
}

function assertString(mixed $value): string
{
	expect($value)->toBeString();
	assert(is_string($value));

	return $value;
}

/**
 * @return array<mixed>
 */
function jsonArray(mixed $json): array
{
	return assertArray(json_decode(assertString($json), true, flags: JSON_THROW_ON_ERROR));
}

function plugin(): Plugin
{
	return assertInstance(Plugin::getInstance(), Plugin::class);
}

/**
 * Reinstalls the plugin in a new app, as Craft sets both before init. Set up the rest of the app afterwards.
 */
function edition(Edition $edition, ?string $version = null): void
{
	assertInstance(TestSuite::getInstance()->test, TestCase::class)->install($edition, $version);
}

/**
 * Created through the container, like Craft does.
 *
 * @param array<string,mixed> $settings
 */
function adapter(array $settings = []): Adapter
{
	// @phpstan-ignore argument.type, argument.templateType (Craft's stub only knows the `class` key)
	return assertInstance(Craft::createObject(['class' => Adapter::class, ...$settings]), Adapter::class);
}

function lettermintApi(): LettermintApi
{
	return assertInstance(Craft::$container->get(LettermintApi::class), LettermintApi::class);
}

function webRequest(): Request
{
	return assertInstance(Craft::$app->getRequest(), Request::class);
}

function webResponse(): craft\web\Response
{
	return assertInstance(Craft::$app->getResponse(), craft\web\Response::class);
}

function addSite(string $uid, string $handle, string $name, bool $enabled = true, bool $primary = false): Site
{
	$site = new Site(['uid' => $uid, 'handle' => $handle, 'name' => $name, 'language' => 'en-US', 'enabled' => $enabled, 'primary' => $primary, 'baseUrl' => "https://{$handle}.test/"]);
	assertInstance(Craft::$app->getSites(), Sites::class)->add($site);

	return $site;
}

/**
 * Craft runs without a cache when none is configured.
 */
function withoutCache(): void
{
	Craft::$app->set('cache', null);
}

/**
 * @return list<string>
 */
function loggedWarnings(): array
{
	$warnings = [];

	foreach (Craft::getLogger()->messages as $message) {
		if (is_array($message) && $message[1] === Logger::LEVEL_WARNING && $message[2] === 'lettermint') {
			$warnings[] = assertString($message[0]);
		}
	}

	return $warnings;
}

function signIn(bool $admin = true): void
{
	Craft::$app->getUser()->setIdentity(new User(['admin' => $admin]));
}

/**
 * Sets Lettermint as the email transport.
 *
 * @param array<string,mixed> $settings
 */
function lettermintEmail(array $settings, string $fromEmail = 'hello@acme.test'): void
{
	Craft::$app->getProjectConfig()->set('email', [
		'fromEmail' => $fromEmail,
		'transportType' => Adapter::class,
		'transportSettings' => $settings,
	]);
}

/**
 * Responds by path, with 404 for others.
 *
 * @param array<string,mixed> $responses
 * @return Closure(RequestInterface):ResponseInterface
 */
function teamApiResponses(array $responses): Closure
{
	return static function (RequestInterface $request) use ($responses): Response {
		$path = substr($request->getUri()->getPath(), strlen('/v1'));

		return isset($responses[$path]) ? jsonResponse($responses[$path]) : new Response(404);
	};
}

/** Sender and recipient addresses. */
const SENDER_EMAIL = 'sender@example.test';
const RECIPIENT_EMAIL = 'recipient@example.test';

const TEAM_API_RESPONSES = [
	'/ping' => 'pong',
	'/team' => [
		'id' => 'team-1',
		'name' => 'Acme Inc',
		'type' => 'business',
		'plan' => 'growth',
		'included_volume' => 50000,
		'verified_at' => '2026-01-01T00:00:00Z',
		'created_at' => '2026-01-01T00:00:00Z',
		'domains_count' => 2,
		'projects_count' => 2,
		'members_count' => 3,
	],
	'/team/usage' => [
		'current_period' => [
			'usage' => 12500,
			'last_incremented_at' => '2026-11-03T00:00:00Z',
			// Contains the current time.
			'period_start' => '2020-01-01T00:00:00Z',
			'period_end' => '2099-12-31T23:59:59Z',
		],
		'historical_usage' => [],
	],
	'/stats' => [
		'from' => '2026-10-06',
		'to' => '2026-11-04',
		'totals' => [
			'sent' => 1000,
			'delivered' => 980,
			'hard_bounced' => 15,
			'spam_complaints' => 2,
			'opened' => 490,
			'clicked' => null,
			'inbound' => ['received' => 0],
		],
		'daily' => [],
	],
	'/projects/project-1' => [
		'id' => 'project-1',
		'name' => 'Website',
		'delivery_mode' => 'sandbox',
		'token_last_used_at' => '2026-11-03T12:00:00Z',
		'token_last_used_ip' => '203.0.113.7',
		'smtp_enabled' => true,
		'redact_email_content' => false,
		'created_at' => '2026-01-15T10:00:00Z',
		'routes' => [
			['id' => 'route-1', 'slug' => 'outgoing', 'route_type' => 'transactional', 'is_default' => true, 'settings' => ['track_opens' => true, 'track_clicks' => false]],
			['id' => 'route-2', 'slug' => 'newsletter', 'route_type' => 'broadcast', 'is_default' => false],
			['id' => 'route-3', 'slug' => 'inbox', 'route_type' => 'inbound', 'is_default' => false],
		],
		'domains' => [
			['id' => 'domain-1', 'domain' => 'acme.test'],
		],
	],
	// Domains of other projects.
	'/domains' => [
		'data' => [
			['id' => 'domain-1', 'domain' => 'acme.test', 'status' => 'verified', 'status_changed_at' => '2026-02-01T00:00:00Z'],
			['id' => 'domain-2', 'domain' => 'mail.acme.test', 'status' => 'failed_verification', 'status_changed_at' => null],
		],
		'next_cursor' => 'next',
	],
];

const HEALTH_TOKEN = 'health-token-0123456789';

const HEALTH_RESPONSES = [
	'/ping' => 'pong',
	'/team' => ['id' => 'team-1', 'name' => 'Acme Inc', 'included_volume' => 1000],
	'/team/usage' => [
		'current_period' => ['usage' => 250, 'period_start' => '2020-01-01T00:00:00Z', 'period_end' => '2099-12-31T23:59:59Z'],
	],
	'/stats' => ['totals' => [], 'daily' => []],
	'/projects/project-1' => [
		'id' => 'project-1',
		'name' => 'Website',
		'delivery_mode' => 'live',
		'routes' => [['id' => 'route-1', 'slug' => 'outgoing', 'route_type' => 'transactional', 'is_default' => true]],
		'domains' => [['id' => 'domain-1', 'domain' => 'acme.test']],
	],
	'/domains' => ['data' => [['id' => 'domain-1', 'domain' => 'acme.test', 'status' => 'verified']], 'next_cursor' => null],
];

/**
 * Sets Lettermint as the email transport.
 *
 * @param array<string,mixed> $settings
 */
function healthEmail(array $settings): void
{
	Craft::$app->getProjectConfig()->set('email', [
		'fromEmail' => 'hello@acme.test',
		'transportType' => Adapter::class,
		'transportSettings' => ['token' => 'secret', 'healthToken' => HEALTH_TOKEN, ...$settings],
	]);
}

/**
 * Responds by path, with 404 for others.
 *
 * @param array<string,mixed> $responses
 * @return Closure(RequestInterface):ResponseInterface
 */
function healthResponses(array $responses = HEALTH_RESPONSES): Closure
{
	return static function (RequestInterface $request) use ($responses): Response {
		$path = substr($request->getUri()->getPath(), strlen('/v1'));

		return isset($responses[$path]) ? jsonResponse($responses[$path]) : new Response(404);
	};
}

/**
 * Returns the payload sent to Lettermint.
 *
 * @param array<string,mixed> $settings
 * @param ?callable(CraftMessage):void $prepare
 * @return array<mixed>
 */
function sendForSite(array $settings, string $siteUid, ?callable $prepare = null): array
{
	$body = null;

	$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): JsonMockResponse {
		$body = $options['body'] ?? null;

		return new JsonMockResponse(['message_id' => 'message-123'], ['http_code' => 202]);
	});

	$adapter = MailerHelper::createTransportAdapter(Adapter::class, ['token' => 'secret'] + $settings);

	/** @var AbstractHttpTransport $transport */
	$transport = $adapter->defineTransport();

	// The transport creates its own HTTP client, which is swapped for one answering with a mocked response.
	(new ReflectionProperty(AbstractHttpTransport::class, 'client'))->setValue($transport, $client);

	// As Craft does while sending.
	Craft::$app->getSites()->setCurrentSite(new Site(['uid' => $siteUid, 'language' => 'en-US']));

	$message = assertInstance((new Mailer(['messageClass' => CraftMessage::class, 'transport' => $transport]))->compose(), CraftMessage::class)
		->setFrom(SENDER_EMAIL)
		->setTo(RECIPIENT_EMAIL)
		->setSubject('Hello')
		->setTextBody('Hello there');

	if ($prepare !== null) {
		$prepare($message);
	}

	expect($message->send())->toBeTrue();

	return jsonArray($body);
}

/**
 * Sends without Craft or a current site, as during install. Returns the payload sent to Lettermint.
 *
 * @param array<string,mixed> $settings
 * @return array<mixed>
 */
function sendThroughTransport(array $settings = []): array
{
	$body = null;

	$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): JsonMockResponse {
		$body = $options['body'] ?? null;

		return new JsonMockResponse(['message_id' => 'message-123'], ['http_code' => 202]);
	});

	/** @var AbstractHttpTransport $transport */
	$transport = MailerHelper::createTransportAdapter(Adapter::class, ['token' => 'secret'] + $settings)->defineTransport();
	(new ReflectionProperty(AbstractHttpTransport::class, 'client'))->setValue($transport, $client);

	$transport->send((new Email())->from(SENDER_EMAIL)->to(RECIPIENT_EMAIL)->subject('Hello')->text('Hello there'));

	return jsonArray($body);
}

/**
 * Runs a utility controller action like the script does, returning the parts. POST reloads, like refresh.
 *
 * @return array<string,string>
 */
function utilityAction(string $action, string $method = 'GET'): array
{
	$_SERVER['REQUEST_METHOD'] = $method;

	$request = webRequest();
	$request->setIsCpRequest(true);
	$request->enableCsrfValidation = false;

	try {
		$response = assertInstance(Craft::$app->runAction($action), craft\web\Response::class);
	} finally {
		unset($_SERVER['REQUEST_METHOD']);
	}

	/** @var array<string,string> */
	return assertArray($response->data);
}

function utilityContent(): string
{
	return utilityAction('lettermint/utility/content')['content'] ?? '';
}

function widgetHtml(): string
{
	return utilityAction('lettermint/utility/widget')['widget'] ?? '';
}

/**
 * The content and footer the script loads.
 */
function utilityHtml(): string
{
	return implode('', utilityAction('lettermint/utility/content'));
}

/**
 * E.g. the props of Vue-rendered inputs.
 */
function registeredJs(): string
{
	$js = [];

	foreach (Craft::$app->getView()->js as $scripts) {
		foreach (assertArray($scripts) as $script) {
			$js[] = assertString($script);
		}
	}

	return implode("\n", $js);
}
