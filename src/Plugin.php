<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\base\Plugin as PluginBase;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\MailerHelper;
use craft\mail\Mailer;
use craft\services\Dashboard;
use craft\services\Utilities;
use GuzzleHttp\ClientInterface;
use SomehowDigital\Craft\Lettermint\api\LettermintApi;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\mail\Metadata;
use SomehowDigital\Craft\Lettermint\services\StatusService;
use SomehowDigital\Craft\Lettermint\services\WebhooksService;
use SomehowDigital\Craft\Lettermint\utilities\LettermintUtility;
use SomehowDigital\Craft\Lettermint\widgets\LettermintWidget;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\di\Container;
use yii\mail\MailEvent;

/**
 * Wires the plugin into Craft: it registers its transport adapter, utility and widget, and binds its components and
 * their dependencies in the container, which they get through their constructors.
 *
 * Only `webhooks` is a public component, whose events projects listen to. The other components are internal.
 *
 * @property StatusService $status The settings of the adapter of the email settings, the health report and the data
 * of the utility and the widget, as checked with Lettermint (internal).
 * @property WebhooksService $webhooks The events of the deliveries of the webhook endpoint (Pro edition).
 */
final class Plugin extends PluginBase
{
	/**
	 * The components of the plugin.
	 *
	 * @return array{components:array<string,array{class:class-string}>}
	 */
	public static function config(): array
	{
		return [
			'components' => [
				'status' => ['class' => StatusService::class],
				'webhooks' => ['class' => WebhooksService::class],
			],
		];
	}

	/**
	 * @return list<string>
	 */
	public static function editions(): array
	{
		return array_map(static fn(Edition $edition): string => $edition->value, Edition::cases());
	}

	/**
	 * The plugin, which Craft loads before any of its components, controllers or templates are used.
	 *
	 * @throws InvalidConfigException when the plugin is not loaded.
	 */
	public static function instance(): self
	{
		return self::getInstance() ?? throw new InvalidConfigException('The Lettermint plugin is not loaded.');
	}

	/**
	 * Whether the plugin runs in the pro edition.
	 */
	public static function isPro(): bool
	{
		return self::getInstance()?->is(Edition::Pro->value) ?? false;
	}

	/**
	 * @internal
	 */
	public function getStatus(): StatusService
	{
		/** @var StatusService */
		return $this->get('status');
	}

	public function getWebhooks(): WebhooksService
	{
		/** @var WebhooksService */
		return $this->get('webhooks');
	}

	public function init(): void
	{
		// Craft sets up plugins in their constructor, and only sets the settings that this plugin has none of here.
		parent::init(); // @pest-mutate-ignore: RemoveMethodCall

		$this->define();

		Event::on(
			MailerHelper::class,
			MailerHelper::EVENT_REGISTER_MAILER_TRANSPORTS,
			static function (RegisterComponentTypesEvent $event): void {
				$event->types[] = Adapter::class;
			},
		);

		// Craft switches to the site and language of a message before the mailer sends it, which the metadata holds.
		// It is only added in the pro edition, with Lettermint as the transport.
		Event::on(Mailer::class, Mailer::EVENT_BEFORE_SEND, static function (MailEvent $event): void {
			if (self::isPro() && self::instance()->getStatus()->isLettermint()) {
				Metadata::add($event);
			}
		});

		// The utility is only selectable in the pro edition.
		Event::on(
			Utilities::class,
			Utilities::EVENT_REGISTER_UTILITIES,
			static function (RegisterComponentTypesEvent $event): void {
				$event->types[] = LettermintUtility::class;
			},
		);

		// The widget is only selectable in the pro edition, by users who may open the utility.
		Event::on(
			Dashboard::class,
			Dashboard::EVENT_REGISTER_WIDGET_TYPES,
			static function (RegisterComponentTypesEvent $event): void {
				$event->types[] = LettermintWidget::class;
			},
		);
	}

	/**
	 * Binds the dependencies of the adapter and the components in the container.
	 *
	 * The adapter gets the edition when it is created, as Craft sets the edition after it initializes the plugin when it
	 * installs it, and switches it within a request.
	 */
	private function define(): void
	{
		Craft::$container->setDefinitions([
			Adapter::class => static function (Container $container, array $params, array $config): Adapter {
				/** @var array<string,mixed> $config Yii configures objects by the names of their properties. */
				return new Adapter([...$config, 'pro' => self::isPro()]);
			},
			// The HTTP client of Craft can be replaced through the container, which is looked up for each client.
			LettermintApi::class => static fn(Container $container): LettermintApi => new LettermintApi(
				static fn(): ClientInterface => $container->has(ClientInterface::class)
					? $container->get(ClientInterface::class)
					: Craft::createGuzzleClient(),
			),
			StatusService::class => ['version' => $this->getVersion()],
		]);
	}
}
