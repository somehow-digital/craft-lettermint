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

/** The classes at the root of the plugin, which Craft and projects reach it through. */
const ROOT_CLASSES = ['Adapter', 'Plugin'];

/** The namespaces of the plugin. A new one is added here, and to the rules of its dependencies below. */
const NAMESPACES = [
	'api',
	'console',
	'controllers',
	'enums',
	'events',
	'helpers',
	'mail',
	'models',
	'services',
	'utilities',
	'validators',
	'web',
	'widgets',
];

/** The namespaces that serve the control panel, the console and the web, which nothing else of the plugin depends on. */
const PRESENTATION = ['console', 'controllers', 'utilities', 'web', 'widgets'];

/** The helpers that depend on nothing of the plugin, so the models may use them. */
const LEAF_HELPERS = [PLUGIN_NAMESPACE . '\helpers\Env', PLUGIN_NAMESPACE . '\helpers\Field'];

/** The classes that projects may use. Every other class of the plugin is internal, so it can change in any release. */
const PUBLIC_API = [
	PLUGIN_NAMESPACE . '\Adapter',
	PLUGIN_NAMESPACE . '\Plugin',
	PLUGIN_NAMESPACE . '\events\WebhookEvent',
	PLUGIN_NAMESPACE . '\services\WebhooksService',
];

/** The internal classes that the tests extend to capture their output or to replace their results. */
const EXTENDED_BY_TESTS = [
	PLUGIN_NAMESPACE . '\console\controllers\HealthController',
	PLUGIN_NAMESPACE . '\services\ChecksService',
];

/**
 * Returns the given namespaces of the plugin, fully qualified.
 *
 * @return list<string>
 */
function pluginNamespaces(string ...$names): array
{
	return array_values(array_map(static fn(string $name): string => PLUGIN_NAMESPACE . '\\' . $name, $names));
}

/**
 * Returns the classes at the root of the plugin and its namespaces, except the given ones, fully qualified.
 *
 * @return list<string>
 */
function pluginNamespacesExcept(string ...$names): array
{
	return [
		...pluginNamespaces(...ROOT_CLASSES),
		...pluginNamespaces(...array_diff(NAMESPACES, $names)),
	];
}

/**
 * Expects each of the subjects not to use the targets.
 *
 * Pest passes `expect([...])->not->toUse()` as soon as the first subject does not use a target, without checking
 * the others, so each subject is expected on its own.
 *
 * @param list<string> $subjects
 * @param list<string>|string $targets
 * @param list<string> $ignoring
 */
function expectNotToUse(array $subjects, array|string $targets, array $ignoring = []): void
{
	foreach ($subjects as $subject) {
		expect($subject)->not->toUse($targets)->ignoring($ignoring);
	}
}

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

test('every namespace of the plugin has rules', function (): void {
	$src = dirname(__DIR__, 2) . '/src/';
	$namespaces = [];
	$roots = [];

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
		if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
			continue;
		}

		$path = explode('/', substr($file->getPathname(), strlen($src)), 2);

		if (count($path) === 1) {
			$roots[] = $file->getBasename('.php');
		} else {
			$namespaces[] = $path[0];
		}
	}

	$namespaces = array_unique($namespaces);
	sort($namespaces);
	sort($roots);

	expect($namespaces)->toBe(NAMESPACES);
	expect($roots)->toBe(ROOT_CLASSES);
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
	expect(PLUGIN_NAMESPACE . '\utilities')
		->toExtend(Utility::class)
		->toHaveSuffix('Utility');
});

arch('widgets are Craft widgets', function (): void {
	expect(PLUGIN_NAMESPACE . '\widgets')
		->toExtend(Widget::class)
		->toHaveSuffix('Widget');
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

arch('helpers and the clients of the API are classes', function (): void {
	expect(pluginNamespaces('helpers', 'api'))->toBeClasses();
});

arch('the enums namespace holds enums', function (): void {
	expect(PLUGIN_NAMESPACE . '\enums')->toBeEnums();
});

arch('enums are backed by strings', function (): void {
	expect(PLUGIN_NAMESPACE)->enums()->toBeStringBackedEnums()->ignoring(TESTS_NAMESPACE);
});

arch('models are immutable', function (): void {
	expect(PLUGIN_NAMESPACE . '\models')
		->toBeClasses()
		->toBeReadonly();
});

arch('internal classes are final', function (): void {
	expect(PLUGIN_NAMESPACE)
		->classes()
		->toBeFinal()
		->ignoring([...PUBLIC_API, ...EXTENDED_BY_TESTS, TESTS_NAMESPACE]);
});

arch('only the api namespace sends requests to Lettermint', function (): void {
	expect(PLUGIN_NAMESPACE)->not->toUse([
		Lettermint::class,
		'Lettermint\SymfonyMailer\Transport',
		'GuzzleHttp',
		HttpClient::class,
	])->ignoring([PLUGIN_NAMESPACE . '\api', TESTS_NAMESPACE]);
});

arch('the presentation does not use the api namespace', function (): void {
	expectNotToUse(pluginNamespaces(...PRESENTATION), PLUGIN_NAMESPACE . '\api');
});

arch('the api namespace depends only on the models, the enums and the helpers', function (): void {
	expect(PLUGIN_NAMESPACE . '\api')->not->toUse(pluginNamespacesExcept('api', 'models', 'enums', 'helpers'));
});

arch('services do not depend on the presentation', function (): void {
	expect(PLUGIN_NAMESPACE . '\services')->not->toUse(pluginNamespaces(...PRESENTATION));
});

arch('the mail namespace does not depend on the api, the services or the presentation', function (): void {
	expect(PLUGIN_NAMESPACE . '\mail')->not->toUse(pluginNamespaces('api', 'services', 'validators', ...PRESENTATION));
});

arch('validators do not depend on the api, the mail, the services or the presentation', function (): void {
	expect(PLUGIN_NAMESPACE . '\validators')->not->toUse(pluginNamespaces('api', 'mail', 'services', ...PRESENTATION));
});

arch('helpers do not depend on the api, the mail, the services or the presentation', function (): void {
	expect(PLUGIN_NAMESPACE . '\helpers')->not->toUse(pluginNamespaces('api', 'mail', 'services', 'validators', ...PRESENTATION));
});

arch('helpers do not depend on Lettermint', function (): void {
	expect(PLUGIN_NAMESPACE . '\helpers')->not->toUse('Lettermint');
});

arch('the leaf helpers do not depend on the plugin', function (): void {
	expectNotToUse(LEAF_HELPERS, PLUGIN_NAMESPACE);
});

arch('events depend on nothing of the plugin', function (): void {
	expect(PLUGIN_NAMESPACE . '\events')->not->toUse(pluginNamespacesExcept('events'));
});

arch('models and enums do not depend on Lettermint, whose objects the api namespace maps to them', function (): void {
	expectNotToUse(pluginNamespaces('models', 'enums'), 'Lettermint');
});

arch('models and enums depend only on each other and the leaf helpers', function (): void {
	expectNotToUse(pluginNamespaces('models', 'enums'), pluginNamespacesExcept('models', 'enums', 'helpers'));
	expectNotToUse(pluginNamespaces('models', 'enums'), PLUGIN_NAMESPACE . '\helpers', LEAF_HELPERS);
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
