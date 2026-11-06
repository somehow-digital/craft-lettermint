<?php

namespace SomehowDigital\Craft\Lettermint\Tests;

use Craft;
use craft\helpers\ArrayHelper;
use craft\services\Config;
use craft\web\Application;
use ArrayObject;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase as BaseTestCase;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\Tests\Support\Plugins;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use yii\base\Event;
use yii\caching\ArrayCache;
use yii\di\Container;

/**
 * Boots a fresh Craft app with the plugin for each test, without a database.
 */
abstract class TestCase extends BaseTestCase
{
	/** @var string[] names of the environment variables set by the test */
	private array $variables = [];

	/** @var array<mixed>[] configs of the Craft app, which are loaded once since they can only be required once */
	private static array $appConfigs;

	/** @var ArrayObject<int,RequestInterface> the requests that a test keeps from its hooks, as `http()` returns them */
	protected ArrayObject $requests;

	protected function setUp(): void
	{
		parent::setUp();

		// Twig deprecates the nodes of Craft that aren't ready for `yield`, which aren't the plugin's to fix.
		$previous = set_error_handler(null);
		restore_error_handler();
		set_error_handler(static function (int $level, string $message, mixed ...$args) use ($previous): bool {
			if (str_contains($message, 'YieldReady') && str_contains($message, 'craft\\web\\twig\\nodes\\')) {
				return true;
			}

			return $previous !== null ? (bool) $previous($level, $message, ...$args) : false;
		}, E_USER_DEPRECATED);

		Craft::$container = new Container();

		// Tests must not reach Lettermint, so requests fail unless a test sets its own responses.
		$this->http(static function (RequestInterface $request): never {
			throw new \LogicException("Unexpected request: {$request->getMethod()} {$request->getUri()}");
		});

		$this->createApp();
	}

	protected function tearDown(): void
	{
		foreach ($this->variables as $name) {
			unset($_SERVER[$name]);
		}

		Event::offAll();
		// @phpstan-ignore assign.propertyType (Yii types the app as always set, which the tests undo between tests.)
		Craft::$app = null;
		Craft::$container = new Container();

		restore_error_handler();

		parent::tearDown();
	}

	protected function createApp(): Application
	{
		$src = CRAFT_VENDOR_PATH . '/craftcms/cms/src';

		$configService = new Config();
		$configService->appType = 'web';
		$configService->env = 'test';
		$configService->configDir = Craft::getAlias('@config');
		$configService->appDefaultsDir = "$src/config/defaults";

		self::$appConfigs ??= [
			self::requireConfig("$src/config/app.php"),
			self::requireConfig("$src/config/app.web.php"),
		];

		/** @var array{class:class-string<Application>} $config The config, of which `app.web.php` sets the class. */
		$config = ArrayHelper::merge(...[
			['vendorPath' => CRAFT_VENDOR_PATH, 'env' => 'test', 'components' => ['config' => $configService]],
			...self::$appConfigs,
			[
				'isInstalled' => false,
				'components' => [
					'log' => ['targets' => []],
					'cache' => ['class' => ArrayCache::class],
					'plugins' => ['class' => Plugins::class],
				],
			],
		]);

		$app = Craft::createObject($config);

		// Tests run on the command line, which requests take for console requests.
		$app->getRequest()->setIsConsoleRequest(false);

		$this->createPlugin();

		return $app;
	}

	/**
	 * @return array<mixed>
	 */
	private static function requireConfig(string $file): array
	{
		$config = require_once $file;

		if (!is_array($config)) {
			throw new \LogicException("The config {$file} is not an array.");
		}

		return $config;
	}

	protected function createPlugin(): Plugin
	{
		/** @var Plugin $plugin */
		$plugin = Craft::createObject([
			'class' => Plugin::class,
			'basePath' => dirname(__DIR__) . '/src',
			// Craft's plugins service falls back to the first edition when none is stored.
			'edition' => Plugin::editions()[0],
		], ['lettermint', Craft::$app]);

		Plugin::setInstance($plugin);
		Craft::$app->setModule('lettermint', $plugin);

		/** @var Plugins $plugins */
		$plugins = Craft::$app->getPlugins();
		$plugins->install($plugin);

		return $plugin;
	}

	/**
	 * Sets the responses of the HTTP client of the SDK, as a response or a callback that takes the request.
	 *
	 * The callback can throw, e.g. a `ConnectException` for a Lettermint that cannot be reached.
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
	 * Sets environment variables, which are removed again after the test.
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
