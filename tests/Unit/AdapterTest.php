<?php

use craft\web\View;
use Lettermint\SymfonyMailer\Transport\LettermintApiTransport;
use SomehowDigital\Craft\Lettermint\Adapter;

/**
 * The value of a private property of an object.
 */
function privateProperty(object $object, string $property): mixed
{
	return (new ReflectionProperty($object, $property))->getValue($object);
}

/**
 * The project token of the transport.
 */
function transportToken(LettermintApiTransport $transport): string
{
	return privateProperty($transport, 'projectToken');
}

/**
 * The default message options of the transport.
 */
function transportOptions(LettermintApiTransport $transport): array
{
	return privateProperty(privateProperty($transport, 'payloadBuilder'), 'defaults');
}

it('is named Lettermint', function () {
	expect(Adapter::displayName())->toBe('Lettermint');
});

it('labels its attributes', function () {
	expect((new Adapter())->attributeLabels())->toBe([
		'token' => 'Token',
		'route' => 'Route',
	]);
});

it('has no token or route by default', function () {
	$adapter = new Adapter();

	expect($adapter->token)->toBeNull();
	expect($adapter->route)->toBeNull();
});

describe('validation', function () {
	it('requires a token', function () {
		$adapter = new Adapter();

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('token');
		expect($adapter->getErrors())->not->toHaveKey('route');
	});

	it('passes with a token and without a route', function () {
		$adapter = new Adapter(['token' => 'secret']);

		expect($adapter->validate())->toBeTrue();
	});

	it('passes with a token and a route', function () {
		$adapter = new Adapter(['token' => 'secret', 'route' => 'outgoing']);

		expect($adapter->validate())->toBeTrue();
	});

	it('trims the token and the route', function () {
		$adapter = new Adapter(['token' => '  secret ', 'route' => ' outgoing  ']);

		expect($adapter->validate())->toBeTrue();
		expect($adapter->token)->toBe('secret');
		expect($adapter->route)->toBe('outgoing');
	});

	it('rejects a token that is only whitespace', function () {
		$adapter = new Adapter(['token' => '   ']);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('token');
	});

	it('validates a token referencing a set environment variable', function () {
		$this->env(['LETTERMINT_TOKEN' => 'secret']);

		$adapter = new Adapter(['token' => '$LETTERMINT_TOKEN']);

		expect($adapter->validate())->toBeTrue();
		expect($adapter->token)->toBe('$LETTERMINT_TOKEN');
	});

	it('rejects a token referencing a missing environment variable', function () {
		$adapter = new Adapter(['token' => '$LETTERMINT_MISSING']);

		expect($adapter->validate())->toBeFalse();
		expect($adapter->getErrors())->toHaveKey('token');
		expect($adapter->token)->toBe('$LETTERMINT_MISSING');
	});
});

describe('transport', function () {
	it('defines a Lettermint API transport', function () {
		$transport = (new Adapter(['token' => 'secret']))->defineTransport();

		expect($transport)->toBeInstanceOf(LettermintApiTransport::class);
	});

	it('passes the token and the route to the transport', function () {
		$transport = (new Adapter(['token' => 'secret', 'route' => 'outgoing']))->defineTransport();

		expect(transportToken($transport))->toBe('secret');
		expect(transportOptions($transport))->toBe(['route' => 'outgoing']);
	});

	it('leaves the route out of the transport when none is set', function () {
		$transport = (new Adapter(['token' => 'secret']))->defineTransport();

		expect(transportOptions($transport))->toBe([]);
	});

	it('leaves the route out of the transport when it is empty', function () {
		$transport = (new Adapter(['token' => 'secret', 'route' => '']))->defineTransport();

		expect(transportOptions($transport))->toBe([]);
	});

	it('resolves environment variables for the transport', function () {
		$this->env([
			'LETTERMINT_TOKEN' => 'env-secret',
			'LETTERMINT_ROUTE' => 'env-route',
		]);

		$transport = (new Adapter(['token' => '$LETTERMINT_TOKEN', 'route' => '$LETTERMINT_ROUTE']))->defineTransport();

		expect(transportToken($transport))->toBe('env-secret');
		expect(transportOptions($transport))->toBe(['route' => 'env-route']);
	});
});

describe('settings', function () {
	beforeEach(function () {
		Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
	});

	it('renders fields for the token and the route', function () {
		$html = (new Adapter(['token' => '$LETTERMINT_TOKEN', 'route' => 'outgoing']))->getSettingsHtml();

		expect($html)
			->toContain('id="token-field"')
			->toContain('API Token')
			->toContain('Lettermint project API token.')
			->toContain('id="route-field"')
			->toContain('Route Slug')
			->toContain('Lettermint project route slug.');
	});

	it('passes the token and the route to the inputs', function () {
		(new Adapter(['token' => '$LETTERMINT_TOKEN', 'route' => 'outgoing']))->getSettingsHtml();

		// The inputs are rendered by Vue, from props registered as JS.
		$js = implode("\n", array_merge(...array_values(Craft::$app->getView()->js)));

		expect($js)
			->toContain('"initialValue":"$LETTERMINT_TOKEN","id":"token","name":"token"')
			->toContain('"initialValue":"outgoing","id":"route","name":"route"');
	});

	it('renders the validation errors', function () {
		$adapter = new Adapter();
		$adapter->validate();

		expect($adapter->getSettingsHtml())->toContain($adapter->getFirstError('token'));
	});
});
