<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\mail;

use Craft;
use craft\elements\User;
use craft\helpers\App;
use craft\mail\Message;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\enums\MetadataKey;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Email;
use yii\mail\MailEvent;

/**
 * Adds the Craft context of a message as Lettermint metadata: its system message key, its site, its language, the
 * user it is sent to and the environment.
 *
 * The metadata is added right before the mailer sends the message, once Craft switched to its site and language.
 * Metadata set on the message itself takes precedence over this.
 *
 * @internal
 */
final class Metadata
{
	/**
	 * Adds the metadata to a message the mailer is about to send through Lettermint. The plugin only adds it in the pro
	 * edition.
	 */
	public static function add(MailEvent $event): void
	{
		$message = $event->message;

		if (!$message instanceof Message || App::mailSettings()->transportType !== Adapter::class) {
			return;
		}

		$email = $message->getSymfonyEmail();
		$headers = $email->getHeaders();
		$existing = [];

		foreach ($headers->all() as $header) {
			if ($header instanceof MetadataHeader) {
				// Only the keys are looked up, with `isset()`, which any value but null passes.
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
	 * The metadata of a message.
	 *
	 * @return array<string,string>
	 */
	private static function metadata(Message $message, Email $email): array
	{
		$metadata = [];

		if ($message->key !== null) {
			$metadata[MetadataKey::Email->value] = $message->key;
		}

		// Craft sets the site of the message as the current site while sending.
		$metadata[MetadataKey::Site->value] = CurrentSite::find()?->handle;

		// Craft keeps the user a message is sent to as a variable, which can also be another user the message is about.
		$user = $message->variables['user'] ?? null;

		if ($user instanceof User && $user->id !== null && self::isRecipient($email, $user)) {
			$metadata[MetadataKey::User->value] = (string) $user->id;
		}

		// Craft switches to the language of system messages while sending them. Other messages are sent in the language
		// Craft runs in, whatever the language of the message, which `setTo()` sets to the preferred one of the user.
		$metadata[MetadataKey::Language->value] = Craft::$app->language;
		$metadata[MetadataKey::Environment->value] = Craft::$app->env;

		return array_filter($metadata, static fn(?string $value): bool => $value !== null && $value !== '');
	}

	/**
	 * Whether the email is sent to the email address of the user.
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
