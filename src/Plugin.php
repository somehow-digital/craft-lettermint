<?php

namespace SomehowDigital\Craft\Lettermint;

use craft\base\Plugin as PluginBase;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\MailerHelper;
use yii\base\Event;

class Plugin extends PluginBase
{
	public function init(): void
	{
		parent::init();

		Event::on(
			MailerHelper::class,
			MailerHelper::EVENT_REGISTER_MAILER_TRANSPORTS,
			function(RegisterComponentTypesEvent $event) {
				$event->types[] = Adapter::class;
			}
		);
	}
}
