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
 * Registers the adapter, utility and widget, and binds the components and their dependencies in the container.
 *
 * Only `webhooks` is public; the other components are internal.
 *
 * @property StatusService $status Settings, health report and utility data (internal).
 * @property WebhooksService $webhooks Webhook delivery events (Pro edition).
 */
final class Plugin extends PluginBase
{
	/**
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
	 * @throws InvalidConfigException when the plugin is not loaded.
	 */
	public static function instance(): self
	{
		return self::getInstance() ?? throw new InvalidConfigException('The Lettermint plugin is not loaded.');
	}

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
		// Only sets settings, which this plugin has none of.
		parent::init(); // @pest-mutate-ignore: RemoveMethodCall

		$this->define();

		Event::on(
			MailerHelper::class,
			MailerHelper::EVENT_REGISTER_MAILER_TRANSPORTS,
			static function (RegisterComponentTypesEvent $event): void {
				$event->types[] = Adapter::class;
			},
		);

		// Craft has switched to the message's site and language by now.
		Event::on(Mailer::class, Mailer::EVENT_BEFORE_SEND, static function (MailEvent $event): void {
			if (self::isPro() && self::instance()->getStatus()->isLettermint()) {
				Metadata::add($event);
			}
		});

		Event::on(
			Utilities::class,
			Utilities::EVENT_REGISTER_UTILITIES,
			static function (RegisterComponentTypesEvent $event): void {
				$event->types[] = LettermintUtility::class;
			},
		);

		Event::on(
			Dashboard::class,
			Dashboard::EVENT_REGISTER_WIDGET_TYPES,
			static function (RegisterComponentTypesEvent $event): void {
				$event->types[] = LettermintWidget::class;
			},
		);
	}

	/**
	 * The adapter reads the edition on creation, as Craft sets it after init on install and can switch it within a request.
	 */
	private function define(): void
	{
		Craft::$container->setDefinitions([
			Adapter::class => static function (Container $container, array $params, array $config): Adapter {
				/** @var array<string,mixed> $config */
				return new Adapter([...$config, 'pro' => self::isPro()]);
			},
			// Resolved per client, so projects can replace Craft's HTTP client in the container.
			LettermintApi::class => static fn(Container $container): LettermintApi => new LettermintApi(
				static fn(): ClientInterface => $container->has(ClientInterface::class)
					? $container->get(ClientInterface::class)
					: Craft::createGuzzleClient(),
			),
			StatusService::class => ['version' => $this->getVersion()],
		]);
	}
}
