<?php

declare(strict_types=1);

use craft\base\Plugin;
use craft\base\Utility;
use craft\base\Widget;
use craft\console\Controller as ConsoleController;
use craft\mail\transportadapters\BaseTransportAdapter;
use craft\web\AssetBundle;
use craft\web\Controller as WebController;
use Lettermint\Lettermint;
use Pest\Preset;
use Symfony\Component\HttpClient\HttpClient;
use yii\base\Component;
use yii\base\Event;
use yii\validators\Validator;

/** The namespace of the plugin, and of its tests, which may debug and assert. */
const PLUGIN_NAMESPACE = 'SomehowDigital\Craft\Lettermint';
const TESTS_NAMESPACE = PLUGIN_NAMESPACE . '\Tests';

/** The classes that projects may use. Every other class of the plugin is internal, so it can change in any release. */
const PUBLIC_API = [
	PLUGIN_NAMESPACE . '\Adapter',
	PLUGIN_NAMESPACE . '\Plugin',
	PLUGIN_NAMESPACE . '\events\WebhookEvent',
	PLUGIN_NAMESPACE . '\services\WebhooksService',
];

arch('it does not debug or print', function (): void {
	(new Preset())->php()->ignoring(TESTS_NAMESPACE);
});

arch('it does not use insecure functions', function (): void {
	(new Preset())->security()->ignoring(TESTS_NAMESPACE);
});

arch('it does not sleep', function (): void {
	expect(['sleep', 'usleep'])->not->toBeUsed();
});

arch('it reads the environment through Craft', function (): void {
	expect(['getenv', 'putenv'])->not->toBeUsed()->ignoring(TESTS_NAMESPACE);
});

arch('it declares strict types', function (): void {
	expect(PLUGIN_NAMESPACE)->toUseStrictTypes();
});

arch('it compares strictly', function (): void {
	expect(PLUGIN_NAMESPACE)->toUseStrictEquality();
});

arch('it does not depend on the tests', function (): void {
	expect(PLUGIN_NAMESPACE)->not->toUse(TESTS_NAMESPACE)->ignoring(TESTS_NAMESPACE);
});

arch('web controllers are Craft controllers', function (): void {
	expect(PLUGIN_NAMESPACE . '\controllers')
		->toExtend(WebController::class)
		->toHaveSuffix('Controller');
});

arch('console controllers are Craft console controllers', function (): void {
	expect(PLUGIN_NAMESPACE . '\console\controllers')
		->toExtend(ConsoleController::class)
		->toHaveSuffix('Controller');
});

arch('events are Yii events', function (): void {
	expect(PLUGIN_NAMESPACE . '\events')
		->toExtend(Event::class)
		->toHaveSuffix('Event');
});

arch('asset bundles are Craft asset bundles', function (): void {
	expect(PLUGIN_NAMESPACE . '\web\assets')
		->toExtend(AssetBundle::class)
		->toHaveSuffix('Asset');
});

arch('services are Yii components', function (): void {
	expect(PLUGIN_NAMESPACE . '\services')
		->toExtend(Component::class)
		->toHaveSuffix('Service');
});

arch('utilities are Craft utilities', function (): void {
	expect(PLUGIN_NAMESPACE . '\utilities')->toExtend(Utility::class);
});

arch('widgets are Craft widgets', function (): void {
	expect(PLUGIN_NAMESPACE . '\widgets')->toExtend(Widget::class);
});

arch('the plugin and the adapter extend their Craft bases', function (): void {
	expect(PLUGIN_NAMESPACE . '\Plugin')->toExtend(Plugin::class);
	expect(PLUGIN_NAMESPACE . '\Adapter')->toExtend(BaseTransportAdapter::class);
});

arch('validators are Yii validators', function (): void {
	expect(PLUGIN_NAMESPACE . '\validators')
		->toExtend(Validator::class)
		->toHaveSuffix('Validator');
});

arch('helpers and the clients of the API are final classes', function (): void {
	expect([PLUGIN_NAMESPACE . '\helpers', PLUGIN_NAMESPACE . '\api'])
		->toBeClasses()
		->toBeFinal();
});

arch('only the api namespace sends requests to Lettermint', function (): void {
	expect(PLUGIN_NAMESPACE)->not->toUse([
		Lettermint::class,
		'Lettermint\SymfonyMailer\Transport',
		'GuzzleHttp',
		HttpClient::class,
	])->ignoring([PLUGIN_NAMESPACE . '\api', TESTS_NAMESPACE]);
});

arch('helpers do not depend on Lettermint', function (): void {
	expect(PLUGIN_NAMESPACE . '\helpers')->not->toUse('Lettermint');
});

arch('the api namespace does not depend on the components of the plugin', function (): void {
	expect(PLUGIN_NAMESPACE . '\api')->not->toUse([
		PLUGIN_NAMESPACE . '\Plugin',
		PLUGIN_NAMESPACE . '\Adapter',
		PLUGIN_NAMESPACE . '\controllers',
		PLUGIN_NAMESPACE . '\console',
		PLUGIN_NAMESPACE . '\mail',
		PLUGIN_NAMESPACE . '\services',
		PLUGIN_NAMESPACE . '\utilities',
		PLUGIN_NAMESPACE . '\validators',
		PLUGIN_NAMESPACE . '\web',
		PLUGIN_NAMESPACE . '\widgets',
	]);
});

arch('services do not depend on the controllers or the control panel', function (): void {
	expect(PLUGIN_NAMESPACE . '\services')->not->toUse([
		PLUGIN_NAMESPACE . '\controllers',
		PLUGIN_NAMESPACE . '\console',
		PLUGIN_NAMESPACE . '\utilities',
		PLUGIN_NAMESPACE . '\web',
		PLUGIN_NAMESPACE . '\widgets',
	]);
});

arch('the enums namespace holds enums', function (): void {
	expect(PLUGIN_NAMESPACE . '\enums')->toBeEnums();
});

arch('models are immutable', function (): void {
	expect(PLUGIN_NAMESPACE . '\models')
		->toBeClasses()
		->toBeFinal()
		->toBeReadonly();
});

arch('models and enums do not depend on the components of the plugin', function (): void {
	expect([PLUGIN_NAMESPACE . '\models', PLUGIN_NAMESPACE . '\enums'])->not->toUse([
		PLUGIN_NAMESPACE . '\Plugin',
		PLUGIN_NAMESPACE . '\Adapter',
		PLUGIN_NAMESPACE . '\api',
		PLUGIN_NAMESPACE . '\controllers',
		PLUGIN_NAMESPACE . '\console',
		PLUGIN_NAMESPACE . '\mail',
		PLUGIN_NAMESPACE . '\services',
		PLUGIN_NAMESPACE . '\utilities',
		PLUGIN_NAMESPACE . '\validators',
		PLUGIN_NAMESPACE . '\web',
		PLUGIN_NAMESPACE . '\widgets',
	]);
});

arch('enums are backed by strings', function (): void {
	expect(PLUGIN_NAMESPACE)->enums()->toBeStringBackedEnums()->ignoring(TESTS_NAMESPACE);
});

test('only the classes of the public API are not internal', function (): void {
	$src = dirname(__DIR__, 2) . '/src/';
	$public = [];

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
		if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
			continue;
		}

		$class = PLUGIN_NAMESPACE . '\\' . str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($src)));

		if (!class_exists($class)) {
			throw new LogicException("{$file->getPathname()} does not declare {$class}.");
		}

		if (!str_contains((string) (new ReflectionClass($class))->getDocComment(), '@internal')) {
			$public[] = $class;
		}
	}

	sort($public);

	expect($public)->toBe(PUBLIC_API);
});
