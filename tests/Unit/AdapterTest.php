<?php

use craft\web\View;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Symfony\Component\Mailer\Bridge\Lettermint\Transport\LettermintApiTransport;

/**
 * The value of a private property of the transport.
 */
function transportProperty(LettermintApiTransport $transport, string $property): mixed
{
	return (new ReflectionProperty($transport, $property))->getValue($transport);
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

		expect(transportProperty($transport, 'token'))->toBe('secret');
		expect(transportProperty($transport, 'route'))->toBe('outgoing');
		expect((string) $transport)->toBe('lettermint+api://api.lettermint.co?route=outgoing');
	});

	it('leaves the route out of the transport when none is set', function () {
		$transport = (new Adapter(['token' => 'secret']))->defineTransport();

		expect(transportProperty($transport, 'route'))->toBeNull();
		expect((string) $transport)->toBe('lettermint+api://api.lettermint.co');
	});

	it('leaves the route out of the transport when it is empty', function () {
		$transport = (new Adapter(['token' => 'secret', 'route' => '']))->defineTransport();

		expect((string) $transport)->toBe('lettermint+api://api.lettermint.co');
	});

	it('resolves environment variables for the transport', function () {
		$this->env([
			'LETTERMINT_TOKEN' => 'env-secret',
			'LETTERMINT_ROUTE' => 'env-route',
		]);

		$transport = (new Adapter(['token' => '$LETTERMINT_TOKEN', 'route' => '$LETTERMINT_ROUTE']))->defineTransport();

		expect(transportProperty($transport, 'token'))->toBe('env-secret');
		expect(transportProperty($transport, 'route'))->toBe('env-route');
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
