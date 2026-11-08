<?php

declare(strict_types=1);

use craft\mail\transportadapters\Sendmail;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\services\MailSettingsService;

describe('adapter', function (): void {
	it('resolves the settings of the adapter of the email settings', function (): void {
		lettermintEmail(['token' => 'secret', 'route' => 'shop']);

		expect((new MailSettingsService())->isLettermint())->toBeTrue();
		expect((new MailSettingsService())->settings()?->route)->toBe('shop');
	});

	it('has no adapter when Lettermint is not the transport', function (): void {
		Craft::$app->getProjectConfig()->set('email', ['fromEmail' => 'hello@acme.test', 'transportType' => Sendmail::class]);

		expect((new MailSettingsService())->isLettermint())->toBeFalse();
		expect((new MailSettingsService())->adapter())->toBeNull();
	});
});

describe('cache key', function (): void {
	it('names the data that it is for', function (): void {
		expect((new MailSettingsService())->cacheKey(new Settings(token: 'secret'), 'health'))->toMatch('/^lettermint\.health\.[0-9a-f]{64}$/');
	});

	it('changes with the system email address', function (): void {
		$adapter = new Adapter(['token' => 'secret']);
		$key = (new MailSettingsService())->cacheKey($adapter->resolve(), 'health');

		Craft::$app->getProjectConfig()->set('email', ['fromEmail' => 'other@acme.test', 'transportType' => Adapter::class]);

		expect((new MailSettingsService())->cacheKey($adapter->resolve(), 'health'))->not->toBe($key);
	});

	it('changes with the value of an environment variable', function (): void {
		$_SERVER['LETTERMINT_TEST_TOKEN'] = 'secret';

		try {
			$adapter = new Adapter(['token' => '$LETTERMINT_TEST_TOKEN']);
			$key = (new MailSettingsService())->cacheKey($adapter->resolve(), 'health');

			$_SERVER['LETTERMINT_TEST_TOKEN'] = 'rotated';

			expect((new MailSettingsService())->cacheKey($adapter->resolve(), 'health'))->not->toBe($key);
		} finally {
			unset($_SERVER['LETTERMINT_TEST_TOKEN']);
		}
	});

	it('changes with the value of an environment variable of a site', function (): void {
		plugin()->edition = Edition::Pro->value;
		$_SERVER['LETTERMINT_TEST_ROUTE'] = 'shop';

		try {
			$adapter = new Adapter(['token' => 'secret', 'sites' => ['site-a' => ['route' => '$LETTERMINT_TEST_ROUTE']]]);
			$key = (new MailSettingsService())->cacheKey($adapter->resolve(), 'health');

			$_SERVER['LETTERMINT_TEST_ROUTE'] = 'archive';

			expect((new MailSettingsService())->cacheKey($adapter->resolve(), 'health'))->not->toBe($key);
		} finally {
			unset($_SERVER['LETTERMINT_TEST_ROUTE']);
		}
	});

	it('has a key without the plugin', function (): void {
		Plugin::setInstance(null);

		expect((new MailSettingsService())->cacheKey(new Settings(token: 'secret'), 'health'))->toStartWith('lettermint.health.');
	});
});
