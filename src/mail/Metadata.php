<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\mail;

use Craft;
use craft\elements\User;
use craft\mail\Message;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Email;
use yii\mail\MailEvent;

/**
 * Adds Craft context as Lettermint metadata: system message key, site, language, recipient user and environment.
 *
 * Metadata set on the message takes precedence.
 *
 * @internal
 */
final class Metadata
{
	public static function add(MailEvent $event): void
	{
		$message = $event->message;

		if (!$message instanceof Message) {
			return;
		}

		$email = $message->getSymfonyEmail();
		$headers = $email->getHeaders();
		$existing = [];

		foreach ($headers->all() as $header) {
			if ($header instanceof MetadataHeader) {
				// Any non-null value passes `isset()`.
				$existing[$header->getKey()] = true; // @pest-mutate-ignore: TrueToFalse
			}
		}

		foreach (self::metadata($message, $email) as $key => $value) {
			if (!isset($existing[$key])) {
				$headers->add(new MetadataHeader($key, $value));
			}
		}
	}

	/**
	 * @return array<string,string>
	 */
	private static function metadata(Message $message, Email $email): array
	{
		$metadata = [];

		if ($message->key !== null) {
			$metadata['craft_email'] = $message->key;
		}

		$metadata['craft_site'] = CurrentSite::find()?->handle;

		// May also be a user the message is about rather than its recipient.
		$user = $message->variables['user'] ?? null;

		if ($user instanceof User && $user->id !== null && self::isRecipient($email, $user)) {
			$metadata['craft_user'] = (string) $user->id;
		}

		// Craft only switches to the message language for system messages; others use the app language.
		$metadata['craft_language'] = Craft::$app->language;
		$metadata['craft_environment'] = Craft::$app->env;

		return array_filter($metadata, static fn(?string $value): bool => $value !== null && $value !== '');
	}

	/**
	 * Whether the user is a recipient.
	 */
	private static function isRecipient(Email $email, User $user): bool
	{
		if (!is_string($user->email)) {
			return false;
		}

		foreach ([...$email->getTo(), ...$email->getCc(), ...$email->getBcc()] as $address) {
			if (strcasecmp($address->getAddress(), $user->email) === 0) {
				return true;
			}
		}

		return false;
	}
}
