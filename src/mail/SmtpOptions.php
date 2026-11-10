<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\mail;

use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\SiteSettings;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Sends the route and tags of the current site as Lettermint's SMTP headers.
 *
 * Headers set on the message take precedence.
 *
 * @internal
 */
final readonly class SmtpOptions
{
	private const ROUTE = 'X-Lettermint-Route';

	private const TAGS = 'X-LM-Tags';

	public function __construct(
		private Settings $settings,
	) {}

	public function __invoke(MessageEvent $event): void
	{
		$email = $event->getMessage();

		if (!$email instanceof Email) {
			return;
		}

		$headers = $email->getHeaders();
		$uid = CurrentSite::find()?->uid;
		$site = $uid === null ? new SiteSettings() : $this->settings->site($uid);
		$route = $site->route ?? $this->settings->route;

		if ($route !== null && !$headers->has(self::ROUTE)) {
			$headers->addTextHeader(self::ROUTE, $route);
		}

		if (!$headers->has(self::TAGS)) {
			// One header per tag stays below Lettermint's size limit of a header.
			foreach ($this->settings->tags->merge($site->tags)->toArray() as $tag) {
				$headers->addTextHeader(self::TAGS, "{$tag['name']}={$tag['value']}");
			}
		}
	}
}
