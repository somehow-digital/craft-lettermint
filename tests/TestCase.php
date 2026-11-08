<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests;

use ArrayObject;
use Closure;
use Craft;
use craft\helpers\ArrayHelper;
use craft\services\Config;
use craft\web\Application;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use LogicException;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\Tests\Support\Plugins;
use SomehowDigital\Craft\Lettermint\Tests\Support\Sites;
use SomehowDigital\Craft\Lettermint\Tests\Support\WarningException;
use yii\base\Event;
use yii\caching\ArrayCache;
use yii\di\Container;

/**
 * Boots a fresh Craft app with the plugin for each test, without a database.
 */
abstract class TestCase extends BaseTestCase
{
	/** @var string[] env vars set by the test */
	private array $variables = [];

	/** @var array<mixed>[] Craft app configs, cached as they can only be required once */
	private static array $appConfigs;

	/** @var ArrayObject<int,RequestInterface> requests recorded by `http()` in hooks */
	protected ArrayObject $requests;

	protected function setUp(): void
	{
		parent::setUp();

		$previous = set_error_handler(null);
		restore_error_handler();
		set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use ($previous): bool {
			// Craft's Twig nodes aren't `yield`-ready; not ours to fix.
			if ($level === E_USER_DEPRECATED && str_contains($message, 'YieldReady') && str_contains($message, 'craft\\web\\twig\\nodes\\')) {
				return true;
			}

			// Fail on warnings everywhere, including mutants, which PHPUnit doesn't treat as plugin code.
			if (($level & (E_WARNING | E_USER_WARNING)) !== 0 && (error_reporting() & $level) !== 0) {
				throw new WarningException($message, 0, $level, $file, $line);
			}

			return $previous !== null && (bool) $previous($level, $message, $file, $line);
		}, E_USER_DEPRECATED | E_WARNING | E_USER_WARNING);

		Craft::$container = new Container();
		// A fresh logger per test.
		Craft::setLogger(null);

		// Requests fail unless a test mocks them, so tests never reach Lettermint.
		$this->http(static function (RequestInterface $request): never {
			throw new LogicException("Unexpected request: {$request->getMethod()} {$request->getUri()}");
		});

		$this->createApp();
	}

	protected function tearDown(): void
	{
		foreach ($this->variables as $name) {
			unset($_SERVER[$name]);
		}

		Event::offAll();
		// @phpstan-ignore assign.propertyType (Yii types the app as always set)
		Craft::$app = null;
		Craft::$container = new Container();

		restore_error_handler();

		parent::tearDown();
	}

	/**
	 * Reinstalls the plugin in a new app, as Craft sets the edition and version before init.
	 */
	public function install(Edition $edition, ?string $version = null): void
	{
		Event::offAll();

		$this->createApp($edition, $version);
	}

	protected function createApp(Edition $edition = Edition::Standard, ?string $version = null): Application
	{
		$src = CRAFT_VENDOR_PATH . '/craftcms/cms/src';

		$configService = new Config();
		$configService->appType = 'web';
		$configService->env = 'test';
		$configService->configDir = Craft::getAlias('@config');
		$configService->appDefaultsDir = "{$src}/config/defaults";

		self::$appConfigs ??= [
			$this->requireConfig("{$src}/config/app.php"),
			$this->requireConfig("{$src}/config/app.web.php"),
		];

		/** @var array{class:class-string<Application>} $config */
		$config = ArrayHelper::merge(...[
			['vendorPath' => CRAFT_VENDOR_PATH, 'env' => 'test', 'components' => ['config' => $configService]],
			...self::$appConfigs,
			[
				'isInstalled' => false,
				'components' => [
					'log' => ['targets' => []],
					'cache' => ['class' => ArrayCache::class],
					'plugins' => ['class' => Plugins::class],
					'sites' => ['class' => Sites::class],
				],
			],
		]);

		$app = Craft::createObject($config);

		// Otherwise the request would be detected as a console request.
		$app->getRequest()->setIsConsoleRequest(false);

		$this->createPlugin($edition, $version);

		return $app;
	}

	/**
	 * @return array<mixed>
	 */
	private function requireConfig(string $file): array
	{
		$config = require_once $file;

		if (!is_array($config)) {
			throw new LogicException("The config {$file} is not an array.");
		}

		return $config;
	}

	protected function createPlugin(Edition $edition, ?string $version): Plugin
	{
		/** @var Plugin $plugin */
		// @phpstan-ignore argument.type (Craft's stub only knows the `class` key)
		$plugin = Craft::createObject([
			'class' => Plugin::class,
			'basePath' => dirname(__DIR__) . '/src',
			'edition' => $edition->value,
			...($version !== null ? ['version' => $version] : []),
			// Like Craft, merges the plugin's config.
			...Plugin::config(),
		], ['lettermint', Craft::$app]);

		Plugin::setInstance($plugin);
		Craft::$app->setModule('lettermint', $plugin);

		/** @var Plugins $plugins */
		$plugins = Craft::$app->getPlugins();
		$plugins->install($plugin);

		return $plugin;
	}

	/**
	 * Mocks SDK responses with a response or a callback taking the request.
	 *
	 * The callback may throw, e.g. a `ConnectException` to simulate an outage.
	 *
	 * @param ResponseInterface|Closure(RequestInterface):ResponseInterface $responses
	 * @return ArrayObject<int,RequestInterface> The requests that are sent.
	 */
	protected function http(ResponseInterface|Closure $responses): ArrayObject
	{
		/** @var ArrayObject<int,RequestInterface> $requests */
		$requests = new ArrayObject();

		Craft::$container->set(ClientInterface::class, new Client([
			'handler' => static function (RequestInterface $request) use ($responses, $requests): ResponseInterface {
				$requests[] = $request;

				return $responses instanceof ResponseInterface ? $responses : $responses($request);
			},
		]));

		return $requests;
	}

	/**
	 * Removed again after the test.
	 *
	 * @param array<string,string> $variables
	 */
	protected function env(array $variables): void
	{
		foreach ($variables as $name => $value) {
			$_SERVER[$name] = $value;
			$this->variables[] = $name;
		}
	}
}
