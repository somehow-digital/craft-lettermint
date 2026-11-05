<?php

namespace SomehowDigital\Craft\Lettermint;

use craft\base\Plugin as PluginBase;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\MailerHelper;
use craft\mail\Mailer;
use craft\services\Dashboard;
use craft\services\Utilities;
use yii\base\Event;

class Plugin extends PluginBase
{
	public const EDITION_STANDARD = 'standard';
	public const EDITION_PRO = 'pro';

	public static function editions(): array
	{
		return [
			self::EDITION_STANDARD,
			self::EDITION_PRO,
		];
	}

	/**
	 * Whether the plugin runs in the pro edition.
	 */
	public static function isPro(): bool
	{
		return self::getInstance()?->is(self::EDITION_PRO) ?? false;
	}

	public function init(): void
	{
		parent::init();

		Event::on(
			MailerHelper::class,
			MailerHelper::EVENT_REGISTER_MAILER_TRANSPORTS,
			function (RegisterComponentTypesEvent $event) {
				$event->types[] = Adapter::class;
			}
		);

		// The metadata is only added in the pro edition, by the transport.
		Event::on(Mailer::class, Mailer::EVENT_BEFORE_SEND, [Metadata::class, 'capture']);
		Event::on(Mailer::class, Mailer::EVENT_AFTER_SEND, [Metadata::class, 'release']);

		// The utility is only selectable in the pro edition.
		Event::on(
			Utilities::class,
			Utilities::EVENT_REGISTER_UTILITIES,
			function (RegisterComponentTypesEvent $event) {
				$event->types[] = Utility::class;
			}
		);

		// The widget is only selectable in the pro edition, by users who may open the utility.
		Event::on(
			Dashboard::class,
			Dashboard::EVENT_REGISTER_WIDGET_TYPES,
			function (RegisterComponentTypesEvent $event) {
				$event->types[] = Widget::class;
			}
		);
	}
}
