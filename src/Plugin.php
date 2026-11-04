<?php

namespace SomehowDigital\Craft\Lettermint;

use craft\base\Plugin as PluginBase;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\MailerHelper;
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

		// The utility is only selectable in the pro edition.
		Event::on(
			Utilities::class,
			Utilities::EVENT_REGISTER_UTILITIES,
			function (RegisterComponentTypesEvent $event) {
				$event->types[] = Utility::class;
			}
		);
	}
}
