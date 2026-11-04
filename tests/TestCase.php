<?php

namespace SomehowDigital\Craft\Lettermint\Tests;

use Craft;
use craft\helpers\ArrayHelper;
use craft\services\Config;
use craft\web\Application;
use PHPUnit\Framework\TestCase as BaseTestCase;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\Tests\Support\Plugins;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
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

	protected function setUp(): void
	{
		parent::setUp();

		// Twig deprecates the nodes of Craft that aren't ready for `yield`, which aren't the plugin's to fix.
		$previous = set_error_handler(null);
		restore_error_handler();
		set_error_handler(static function (int $level, string $message, ...$args) use ($previous): bool {
			if (str_contains($message, 'YieldReady') && str_contains($message, 'craft\\web\\twig\\nodes\\')) {
				return true;
			}

			return $previous ? (bool) $previous($level, $message, ...$args) : false;
		}, E_USER_DEPRECATED);

		Craft::$container = new Container();

		// Tests must not reach Lettermint, so requests fail unless a test sets its own responses.
		$this->http(static function (string $method, string $url): never {
			throw new \LogicException("Unexpected request: {$method} {$url}");
		});

		$this->createApp();
	}

	protected function tearDown(): void
	{
		foreach ($this->variables as $name) {
			unset($_SERVER[$name]);
		}

		Event::offAll();
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

		$config = ArrayHelper::merge(
			['vendorPath' => CRAFT_VENDOR_PATH, 'env' => 'test', 'components' => ['config' => $configService]],
			require "$src/config/app.php",
			require "$src/config/app.web.php",
			[
				'isInstalled' => false,
				'components' => [
					'log' => ['targets' => []],
					'cache' => ['class' => ArrayCache::class],
					'plugins' => ['class' => Plugins::class],
				],
			],
		);

		/** @var Application $app */
		$app = Craft::createObject($config);

		// Tests run on the command line, which requests take for console requests.
		$app->getRequest()->setIsConsoleRequest(false);

		$this->createPlugin();

		return $app;
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
	 * Sets the responses of the HTTP client, as a response, a list of responses or a callback.
	 *
	 * @param callable|iterable<mixed>|\Symfony\Contracts\HttpClient\ResponseInterface $responses
	 */
	protected function http(mixed $responses): MockHttpClient
	{
		$client = new MockHttpClient($responses);
		Craft::$container->set(HttpClientInterface::class, $client);

		return $client;
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
