<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\base\Plugin as PluginBase;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\MailerHelper;
use craft\helpers\UrlHelper;
use craft\mail\Mailer;
use craft\services\Dashboard;
use craft\services\Utilities;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * @property HealthService $health The health checks of the health endpoint and the console command (Pro edition).
 * @property TeamService $team The data of the utility and the widget, and the clients of the team API (Pro edition).
 */
class Plugin extends PluginBase
{
	/**
	 * The components of the plugin, which projects can configure or replace through the plugin configs of Craft.
	 *
	 * @return array{components:array<string,array{class:class-string}>}
	 */
	public static function config(): array
	{
		return [
			'components' => [
				'health' => ['class' => HealthService::class],
				'team' => ['class' => TeamService::class],
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
	 * The URL of an action of the plugin on the primary site, for the endpoints that Lettermint and monitors call.
	 */
	public static function actionUrl(string $action): string
	{
		return UrlHelper::siteUrl(Craft::$app->getConfig()->getGeneral()->actionTrigger . '/' . $action);
	}

	public function getHealth(): HealthService
	{
		/** @var HealthService */
		return $this->get('health');
	}

	public function getTeam(): TeamService
	{
		/** @var TeamService */
		return $this->get('team');
	}

	public function init(): void
	{
		// Craft sets up plugins in their constructor, and only sets the settings that this plugin has none of here.
		parent::init(); // @pest-mutate-ignore: RemoveMethodCall

		Event::on(
			MailerHelper::class,
			MailerHelper::EVENT_REGISTER_MAILER_TRANSPORTS,
			function (RegisterComponentTypesEvent $event): void {
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
			function (RegisterComponentTypesEvent $event): void {
				$event->types[] = Utility::class;
			},
		);

		// The widget is only selectable in the pro edition, by users who may open the utility.
		Event::on(
			Dashboard::class,
			Dashboard::EVENT_REGISTER_WIDGET_TYPES,
			function (RegisterComponentTypesEvent $event): void {
				$event->types[] = Widget::class;
			},
		);
	}
}
