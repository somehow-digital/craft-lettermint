<?php

declare(strict_types=1);

use craft\web\Request;
use craft\elements\User;
use craft\helpers\MailerHelper;
use craft\mail\Mailer;
use craft\mail\Message as CraftMessage;
use craft\models\Site;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\Tests\TestCase;
use SomehowDigital\Craft\Lettermint\Utility;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

/**
 * A response of Lettermint with the data as JSON.
 */
function jsonResponse(mixed $data, int $status = 200): Response
{
	return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
}

/**
 * The value, failing the test when it is not an instance of the class.
 *
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
 * The value, failing the test when it is not an array.
 *
 * @return array<mixed>
 */
function assertArray(mixed $value): array
{
	expect($value)->toBeArray();
	assert(is_array($value));

	return $value;
}

/**
 * The value, failing the test when it is not a string.
 */
function assertString(mixed $value): string
{
	expect($value)->toBeString();
	assert(is_string($value));

	return $value;
}

/**
 * The array that JSON holds, such as the body of a request or the output of a command.
 *
 * @return array<mixed>
 */
function jsonArray(mixed $json): array
{
	return assertArray(json_decode(assertString($json), true, flags: JSON_THROW_ON_ERROR));
}

/**
 * The plugin, which the test case installs for each test.
 */
function plugin(): Plugin
{
	return assertInstance(Plugin::getInstance(), Plugin::class);
}

/**
 * The request of the app, which the tests send as web requests.
 */
function webRequest(): Request
{
	return assertInstance(Craft::$app->getRequest(), Request::class);
}

/**
 * The response of the app to a web request.
 */
function webResponse(): craft\web\Response
{
	return assertInstance(Craft::$app->getResponse(), craft\web\Response::class);
}

/**
 * Signs in a user, who is an admin and may open all utilities unless told otherwise.
 */
function signIn(bool $admin = true): void
{
	Craft::$app->getUser()->setIdentity(new User(['admin' => $admin]));
}

/**
 * Sets the email settings, with the adapter as the transport.
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
 * Answers the requests of the team API with the given responses by path, and 404 for others.
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
			// The period contains the time the tests run.
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
		'routes' => [
			['id' => 'route-1', 'slug' => 'outgoing', 'route_type' => 'transactional', 'is_default' => true],
			['id' => 'route-2', 'slug' => 'newsletter', 'route_type' => 'broadcast', 'is_default' => false],
			['id' => 'route-3', 'slug' => 'inbox', 'route_type' => 'inbound', 'is_default' => false],
		],
		'domains' => [
			['id' => 'domain-1', 'domain' => 'acme.test'],
		],
	],
	// Domains of other projects, which are left out of the project.
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
 * Sets the email settings, with the adapter as the transport.
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
 * Answers the requests of Lettermint with the given responses by path, and 404 for others.
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
 * Sends a message for a site through the adapter, returning the payload sent to the Lettermint API.
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

	// Craft sets the site of the message as the current site while sending.
	Craft::$app->getSites()->setCurrentSite(new Site(['uid' => $siteUid, 'language' => 'en-US']));

	$message = assertInstance((new Mailer(['messageClass' => CraftMessage::class, 'transport' => $transport]))->compose(), CraftMessage::class)
		->setFrom('sender@example.test')
		->setTo('recipient@example.test')
		->setSubject('Hello')
		->setTextBody('Hello there');

	if ($prepare !== null) {
		$prepare($message);
	}

	expect($message->send())->toBeTrue();

	return jsonArray($body);
}

/**
 * The content, toolbar and footer of the utility.
 */
function utilityHtml(): string
{
	return Utility::toolbarHtml() . Utility::contentHtml() . Utility::footerHtml();
}
