<?php

use SomehowDigital\Craft\Lettermint\Plugin;
use yii\symfonymailer\Message;

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

it('sends emails with the tags of their site added to the tags of the adapter', function () {
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

it('sends emails with the tags of the adapter for sites without tags', function () {
	$payload = sendForSite([
		'tags' => 'environment=production',
		'sites' => ['site-a' => ['route' => 'shop-a']],
	], 'site-a');

	expect($payload['route'])->toBe('shop-a');
	expect($payload['tags'])->toBe([['name' => 'environment', 'value' => 'production']]);
});

it('ignores the site tags in the standard edition', function () {
	Plugin::getInstance()->edition = Plugin::EDITION_STANDARD;

	$payload = sendForSite([
		'tags' => 'environment=production',
		'sites' => ['site-a' => ['tags' => 'site=a']],
	], 'site-a');

	expect($payload['tags'])->toBe([['name' => 'environment', 'value' => 'production']]);
});

it('prefers tags set on the message', function () {
	$payload = sendForSite([
		'sites' => ['site-a' => ['tags' => 'site=a']],
	], 'site-a', function (Message $message) {
		$message->setHeader('X-LM-Options', json_encode(['tags' => [['name' => 'custom', 'value' => 'yes']]]));
	});

	expect($payload['tags'])->toBe([['name' => 'custom', 'value' => 'yes']]);
});
