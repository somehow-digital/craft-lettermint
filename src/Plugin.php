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
use SomehowDigital\Craft\Lettermint\api\ClientFactory;
use SomehowDigital\Craft\Lettermint\api\LettermintApi;
use SomehowDigital\Craft\Lettermint\api\WebhookVerifier;
use SomehowDigital\Craft\Lettermint\base\LettermintApiInterface;
use SomehowDigital\Craft\Lettermint\base\WebhookVerifierInterface;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\mail\Metadata;
use SomehowDigital\Craft\Lettermint\services\ChecksService;
use SomehowDigital\Craft\Lettermint\services\HealthService;
use SomehowDigital\Craft\Lettermint\services\MailSettingsService;
use SomehowDigital\Craft\Lettermint\services\UtilityService;
use SomehowDigital\Craft\Lettermint\services\WebhooksService;
use SomehowDigital\Craft\Lettermint\utilities\LettermintUtility;
use SomehowDigital\Craft\Lettermint\widgets\LettermintWidget;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\di\Container;
use yii\mail\MailEvent;

/**
 * Wires the plugin into Craft: it registers its transport adapter, utility and widget, and binds the dependencies of its
 * components in the container, which they get through their constructors.
 *
 * Only `webhooks` is a public component, whose events projects listen to. The other components are internal.
 *
 * @property ChecksService $checks The checks of the health endpoint, the utility and the widget, with the results of the
 * team API and the status of the project token they are made against (Pro edition, internal).
 * @property HealthService $health The health checks of the health endpoint and the console command (Pro edition,
 * internal).
 * @property MailSettingsService $mailSettings The settings of the adapter of the email settings, and the data cached for
 * them (internal).
 * @property UtilityService $utility The data of the utility and the widget (Pro edition, internal).
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
				'checks' => ['class' => ChecksService::class],
				'health' => ['class' => HealthService::class],
				'mailSettings' => ['class' => MailSettingsService::class],
				'utility' => ['class' => UtilityService::class],
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
	public function getChecks(): ChecksService
	{
		/** @var ChecksService */
		return $this->get('checks');
	}

	/**
	 * @internal
	 */
	public function getHealth(): HealthService
	{
		/** @var HealthService */
		return $this->get('health');
	}

	/**
	 * @internal
	 */
	public function getMailSettings(): MailSettingsService
	{
		/** @var MailSettingsService */
		return $this->get('mailSettings');
	}

	/**
	 * @internal
	 */
	public function getUtility(): UtilityService
	{
		/** @var UtilityService */
		return $this->get('utility');
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
		if ($this->isProEdition()) {
			Event::on(Mailer::class, Mailer::EVENT_BEFORE_SEND, static function (MailEvent $event): void {
				Metadata::add($event);
			});
		}

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
	 * Binds the dependencies of the adapter and the components in the container. Craft sets the edition before the
	 * plugin is initialized, so it is bound with them.
	 */
	private function define(): void
	{
		Craft::$container->setDefinitions([
			Adapter::class => ['pro' => $this->isProEdition()],
			// The HTTP client of Craft can be replaced through the container, which is looked up for each client.
			ClientFactory::class => static fn(Container $container): ClientFactory => new ClientFactory(
				static fn(): ClientInterface => $container->has(ClientInterface::class)
					? $container->get(ClientInterface::class)
					: Craft::createGuzzleClient(),
			),
			LettermintApiInterface::class => LettermintApi::class,
			WebhookVerifierInterface::class => WebhookVerifier::class,
			MailSettingsService::class => ['adapter' => Adapter::class, 'version' => $this->getVersion()],
			UtilityService::class => ['utility' => LettermintUtility::class],
		]);
	}

	private function isProEdition(): bool
	{
		return $this->is(Edition::Pro->value);
	}
}
