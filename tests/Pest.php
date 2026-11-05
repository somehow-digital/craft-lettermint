<?php

use craft\elements\User;
use craft\helpers\MailerHelper;
use craft\mail\Mailer;
use craft\mail\Message as CraftMessage;
use craft\models\Site;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Tests\TestCase;
use SomehowDigital\Craft\Lettermint\Utility;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;
use yii\symfonymailer\Message;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

/**
 * A response of Lettermint with the data as JSON.
 */
function jsonResponse(mixed $data, int $status = 200): Response
{
	return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
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
 */
function teamApiResponses(array $responses): Closure
{
	return static function (RequestInterface $request) use ($responses) {
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
 */
function healthResponses(array $responses = HEALTH_RESPONSES): Closure
{
	return static function (RequestInterface $request) use ($responses) {
		$path = substr($request->getUri()->getPath(), strlen('/v1'));

		return isset($responses[$path]) ? jsonResponse($responses[$path]) : new Response(404);
	};
}

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

	$message = (new Mailer(['messageClass' => CraftMessage::class, 'transport' => $transport]))->compose()
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

/**
 * The content, toolbar and footer of the utility.
 */
function utilityHtml(): string
{
	return Utility::toolbarHtml() . Utility::contentHtml() . Utility::footerHtml();
}
