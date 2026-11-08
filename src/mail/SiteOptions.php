<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\mail;

use Lettermint\SymfonyMailer\Header\OptionsHeader;
use SomehowDigital\Craft\Lettermint\models\Settings;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\Headers;

/**
 * Applies the current site's route and tags to a message.
 *
 * Site tags override adapter tags of the same name. Options set on the message take precedence.
 *
 * @internal
 */
final readonly class SiteOptions
{
	public function __construct(
		private Settings $settings,
	) {}

	public function __invoke(MessageEvent $event): void
	{
		$email = $event->getMessage();

		if (!$email instanceof Email) {
			return;
		}

		$options = $this->options();

		if ($options !== []) {
			$this->apply($email->getHeaders(), $options);
		}
	}

	/**
	 * Existing options on the message win.
	 *
	 * @param array<string,mixed> $options
	 */
	private function apply(Headers $headers, array $options): void
	{
		$header = new OptionsHeader($options);
		$existing = $headers->get($header->getName());

		if ($existing instanceof HeaderInterface) {
			$body = $existing->getBody();
			$overrides = is_string($body) ? json_decode($body, true) : null;

			// Left for the transport to reject.
			if (!is_array($overrides)) {
				return;
			}

			/** @var array<string,mixed> $overrides */
			$header = new OptionsHeader(array_replace($options, $overrides));
			$headers->remove($header->getName());
		}

		$headers->add($header);
	}

	/**
	 * @return array{route?:string,tags?:list<array{name:string,value:string}>}
	 */
	private function options(): array
	{
		if (null === $siteUid = CurrentSite::find()?->uid) {
			return [];
		}

		$site = $this->settings->site($siteUid);
		$options = [];

		if ($site->route !== null) {
			$options['route'] = $site->route;
		}

		if (!$site->tags->isEmpty()) {
			$options['tags'] = $this->settings->tags->merge($site->tags)->toArray();
		}

		return $options;
	}
}
