<?php

declare(strict_types=1);

use craft\mail\transportadapters\Sendmail;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\services\MailSettingsService;

describe('adapter', function (): void {
	it('resolves the settings of the adapter of the email settings', function (): void {
		lettermintEmail(['token' => 'secret', 'route' => 'shop']);

		expect(plugin()->getMailSettings()->isLettermint())->toBeTrue();
		expect(plugin()->getMailSettings()->settings()?->route)->toBe('shop');
	});

	it('has no adapter when Lettermint is not the transport', function (): void {
		Craft::$app->getProjectConfig()->set('email', ['fromEmail' => 'hello@acme.test', 'transportType' => Sendmail::class]);

		expect(plugin()->getMailSettings()->isLettermint())->toBeFalse();
		expect(plugin()->getMailSettings()->settings())->toBeNull();
	});

	it('has no settings without an adapter to read them from', function (): void {
		lettermintEmail(['token' => 'secret']);

		// @phpstan-ignore argument.type (the stub of Craft only knows the class key of the config, not the properties it configures)
		$service = assertInstance(Craft::createObject(['class' => MailSettingsService::class, 'adapter' => null]), MailSettingsService::class);

		expect($service->isLettermint())->toBeFalse();
		expect($service->settings())->toBeNull();
	});
});

describe('cache key', function (): void {
	it('names the data that it is for', function (): void {
		expect(plugin()->getMailSettings()->cacheKey(new Settings(token: 'secret'), 'health'))->toMatch('/^lettermint\.health\.[0-9a-f]{64}$/');
	});

	it('changes with the version of the plugin, which may shape the data differently', function (): void {
		$key = plugin()->getMailSettings()->cacheKey(new Settings(token: 'secret'), 'health');

		edition(Edition::Standard, '99.0.0');

		expect(plugin()->getMailSettings()->cacheKey(new Settings(token: 'secret'), 'health'))->not->toBe($key);
	});

	it('changes with the system email address', function (): void {
		$adapter = adapter(['token' => 'secret']);
		$key = plugin()->getMailSettings()->cacheKey($adapter->resolve(), 'health');

		Craft::$app->getProjectConfig()->set('email', ['fromEmail' => 'other@acme.test', 'transportType' => Adapter::class]);

		expect(plugin()->getMailSettings()->cacheKey($adapter->resolve(), 'health'))->not->toBe($key);
	});

	it('changes with the value of an environment variable', function (): void {
		$_SERVER['LETTERMINT_TEST_TOKEN'] = 'secret';

		try {
			$adapter = adapter(['token' => '$LETTERMINT_TEST_TOKEN']);
			$key = plugin()->getMailSettings()->cacheKey($adapter->resolve(), 'health');

			$_SERVER['LETTERMINT_TEST_TOKEN'] = 'rotated';

			expect(plugin()->getMailSettings()->cacheKey($adapter->resolve(), 'health'))->not->toBe($key);
		} finally {
			unset($_SERVER['LETTERMINT_TEST_TOKEN']);
		}
	});

	it('changes with the value of an environment variable of a site', function (): void {
		edition(Edition::Pro);
		$_SERVER['LETTERMINT_TEST_ROUTE'] = 'shop';

		try {
			$adapter = adapter(['token' => 'secret', 'sites' => ['site-a' => ['route' => '$LETTERMINT_TEST_ROUTE']]]);
			$key = plugin()->getMailSettings()->cacheKey($adapter->resolve(), 'health');

			$_SERVER['LETTERMINT_TEST_ROUTE'] = 'archive';

			expect(plugin()->getMailSettings()->cacheKey($adapter->resolve(), 'health'))->not->toBe($key);
		} finally {
			unset($_SERVER['LETTERMINT_TEST_ROUTE']);
		}
	});
});
