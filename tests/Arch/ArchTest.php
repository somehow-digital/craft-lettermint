<?php

declare(strict_types=1);

use craft\base\Plugin;
use craft\base\Utility;
use craft\base\Widget;
use craft\console\Controller as ConsoleController;
use craft\mail\transportadapters\BaseTransportAdapter;
use craft\web\AssetBundle;
use craft\web\Controller as WebController;
use Pest\Preset;
use yii\base\Event;

/** The namespace of the plugin, and of its tests, which may debug and assert. */
const PLUGIN_NAMESPACE = 'SomehowDigital\Craft\Lettermint';
const TESTS_NAMESPACE = PLUGIN_NAMESPACE . '\Tests';

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

arch('the components extend their Craft bases', function (): void {
	expect(PLUGIN_NAMESPACE . '\Plugin')->toExtend(Plugin::class);
	expect(PLUGIN_NAMESPACE . '\Adapter')->toExtend(BaseTransportAdapter::class);
	expect(PLUGIN_NAMESPACE . '\Utility')->toExtend(Utility::class);
	expect(PLUGIN_NAMESPACE . '\Widget')->toExtend(Widget::class);
});

arch('enums are backed by strings', function (): void {
	expect(PLUGIN_NAMESPACE)->enums()->toBeStringBackedEnums()->ignoring(TESTS_NAMESPACE);
});
