<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\elements\User;
use craft\mail\Message;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Email;
use yii\mail\MailEvent;

/**
 * Adds the Craft context of a message as Lettermint metadata: its system message key, its site, its language, the
 * user it is sent to and the environment.
 *
 * The transport only gets the Symfony email, so the Craft message is captured from the mailer before it is sent.
 * Metadata set on the message itself takes precedence over this.
 */
final class Metadata
{
	public const EMAIL = 'craft_email';
	public const SITE = 'craft_site';
	public const USER = 'craft_user';
	public const LANGUAGE = 'craft_language';
	public const ENVIRONMENT = 'craft_environment';

	/**
	 * The Craft message being sent.
	 */
	private static ?Message $message = null;

	/**
	 * Captures the Craft message before the mailer sends it.
	 */
	public static function capture(MailEvent $event): void
	{
		self::$message = $event->message instanceof Message ? $event->message : null;
	}

	/**
	 * Forgets the Craft message after the mailer sent it.
	 */
	public static function release(): void
	{
		self::$message = null;
	}

	public function __invoke(MessageEvent $event): void
	{
		$email = $event->getMessage();

		if (!$email instanceof Email) {
			return;
		}

		$headers = $email->getHeaders();
		$existing = [];

		foreach ($headers->all() as $header) {
			if ($header instanceof MetadataHeader) {
				// Only the keys are looked up, with `isset()`, which any value but null passes.
				$existing[$header->getKey()] = true; // @pest-mutate-ignore: TrueToFalse
			}
		}

		foreach ($this->metadata($email) as $key => $value) {
			if (!isset($existing[$key])) {
				$headers->add(new MetadataHeader($key, $value));
			}
		}
	}

	/**
	 * The metadata of the message being sent.
	 *
	 * @return array<string,string>
	 */
	private function metadata(Email $email): array
	{
		// The message is forgotten once used, as the mailer does not release it when the transport fails.
		$message = self::$message;
		self::$message = null;
		$metadata = [];

		if ($message?->key !== null) {
			$metadata[self::EMAIL] = $message->key;
		}

		// Craft sets the site of the message as the current site while sending.
		$metadata[self::SITE] = Sites::current()?->handle;

		// Craft keeps the user a message is sent to as a variable, which can also be another user the message is about.
		$user = $message->variables['user'] ?? null;

		if ($user instanceof User && $user->id !== null && $this->isRecipient($email, $user)) {
			$metadata[self::USER] = (string) $user->id;
		}

		// Craft switches to the language of system messages while sending them. Other messages are sent in the language
		// Craft runs in, whatever the language of the message, which `setTo()` sets to the preferred one of the user.
		$metadata[self::LANGUAGE] = Craft::$app->language;
		$metadata[self::ENVIRONMENT] = Craft::$app->env;

		return array_filter($metadata, static fn(?string $value): bool => $value !== null && $value !== '');
	}

	/**
	 * Whether the email is sent to the email address of the user.
	 */
	private function isRecipient(Email $email, User $user): bool
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
