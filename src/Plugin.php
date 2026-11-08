<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use craft\base\Plugin as PluginBase;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\MailerHelper;
use craft\mail\Mailer;
use craft\services\Dashboard;
use craft\services\Utilities;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\mail\Metadata;
use SomehowDigital\Craft\Lettermint\services\ChecksService;
use SomehowDigital\Craft\Lettermint\services\HealthService;
use SomehowDigital\Craft\Lettermint\services\UtilityService;
use SomehowDigital\Craft\Lettermint\services\WebhooksService;
use SomehowDigital\Craft\Lettermint\utilities\LettermintUtility;
use SomehowDigital\Craft\Lettermint\widgets\LettermintWidget;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * Only `webhooks` is a public component, whose events projects listen to. The other components are internal.
 *
 * @property ChecksService $checks The checks of the health endpoint, the utility and the widget, with the results of the
 * team API and the status of the project token they are made against (Pro edition, internal).
 * @property HealthService $health The health checks of the health endpoint and the console command (Pro edition,
 * internal).
 * @property UtilityService $utility The data of the utility and the widget (Pro edition, internal).
 * @property WebhooksService $webhooks The events of the deliveries of the webhook endpoint (Pro edition).
 */
class Plugin extends PluginBase
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

		Event::on(
			MailerHelper::class,
			MailerHelper::EVENT_REGISTER_MAILER_TRANSPORTS,
			static function (RegisterComponentTypesEvent $event): void {
				$event->types[] = Adapter::class;
			},
		);

		// Craft switches to the site and language of a message before the mailer sends it, which the metadata holds.
		// It is only added in the pro edition, with Lettermint as the transport.
		Event::on(Mailer::class, Mailer::EVENT_BEFORE_SEND, Metadata::add(...));

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
}
