<?php

use craft\elements\User;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Tests\TestCase;

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
	'/projects' => [
		'data' => [
			[
				'id' => 'project-1',
				'name' => 'Website',
				'delivery_mode' => 'sandbox',
				'routes_count' => 2,
				'domains_count' => 1,
				'last_28_days' => ['messages_transactional' => 800, 'messages_broadcast' => 150, 'messages_inbound' => 0, 'deliverability' => 98.5],
			],
			[
				'id' => 'project-2',
				'name' => 'Shop',
				'delivery_mode' => 'live',
				'routes_count' => 1,
				'domains_count' => 1,
				'last_28_days' => ['messages_transactional' => 10, 'messages_broadcast' => 0, 'messages_inbound' => 0, 'deliverability' => 100],
			],
		],
		'next_cursor' => null,
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
		'last_28_days' => ['messages_transactional' => 800, 'messages_broadcast' => 150, 'messages_inbound' => 0, 'deliverability' => 98.5],
	],
	'/domains' => [
		'data' => [
			['id' => 'domain-1', 'domain' => 'acme.test', 'status' => 'verified', 'status_changed_at' => '2026-02-01T00:00:00Z'],
			['id' => 'domain-2', 'domain' => 'mail.acme.test', 'status' => 'failed_verification', 'status_changed_at' => null],
		],
		'next_cursor' => 'next',
	],
];
