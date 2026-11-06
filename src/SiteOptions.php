<?php

namespace SomehowDigital\Craft\Lettermint;

use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;

/**
 * Applies the route and tags of the current site to a message, which Craft sets to the site of the message while sending.
 *
 * The tags of the site are added to the tags of the adapter, replacing the ones with the same name.
 * Options set on the message itself take precedence over the ones of the site.
 */
final class SiteOptions
{
	/**
	 * The header of the SDK transport that holds the options of a message.
	 */
	private const HEADER = 'X-LM-Options';

	public function __construct(
		private readonly Adapter $adapter,
	) {
	}

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
	 * Sets the options on the message, below the ones it has itself.
	 *
	 * @param array<string,mixed> $options
	 */
	private function apply(Headers $headers, array $options): void
	{
		if (null !== $header = $headers->get(self::HEADER)) {
			$body = $header->getBody();
			$overrides = is_string($body) ? json_decode($body, true) : null;

			// Invalid options are left to the transport, which rejects them.
			if (!is_array($overrides)) {
				return;
			}

			$options = array_replace($options, $overrides);
			$headers->remove(self::HEADER);
		}

		$headers->addTextHeader(self::HEADER, json_encode($options, JSON_THROW_ON_ERROR));
	}

	/**
	 * The options of the current site.
	 *
	 * @return array{route?:string,tags?:list<array{name:string,value:string}>}
	 */
	private function options(): array
	{
		if (null === $siteUid = Sites::current()?->uid) {
			return [];
		}

		$options = [];

		if (null !== $route = $this->adapter->getSiteRoute($siteUid)) {
			$options['route'] = $route;
		}

		if ([] !== $tags = $this->adapter->getSiteTags($siteUid)) {
			$options['tags'] = Tags::merge($this->adapter->getTagList(), $tags);
		}

		return $options;
	}
}
