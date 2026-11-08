<?php

declare(strict_types=1);

use craft\elements\User;
use craft\mail\Mailer;
use craft\mail\Message;
use craft\mail\transportadapters\Sendmail;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\mail\Metadata;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Email;
use yii\base\Event;
use yii\mail\MailEvent;
use yii\symfonymailer\Message as SymfonyMessage;

/**
 * A recipient with a preferred language, without the database.
 */
function recipient(string $email = RECIPIENT_EMAIL, ?string $language = null): User
{
	$user = new class () extends User {
		public ?string $preferredLanguage = null;

		public function getPreferredLanguage(): ?string
		{
			return $this->preferredLanguage;
		}
	};

	$user->id = 42;
	$user->email = $email;
	$user->preferredLanguage = $language;

	return $user;
}

/**
 * Sets the key after Craft composed the message, as Craft would render a system message for a key set before.
 */
function setMessageKey(string $key): void
{
	Event::on(Mailer::class, Mailer::EVENT_BEFORE_SEND, static function (MailEvent $event) use ($key): void {
		assertInstance($event->message, Message::class)->key = $key;
	}, append: false);
}

/**
 * @return list<string>
 */
function metadataKeys(Email $email): array
{
	$keys = [];

	foreach ($email->getHeaders()->all() as $header) {
		if ($header instanceof MetadataHeader) {
			$keys[] = $header->getKey();
		}
	}

	return $keys;
}

// Each test boots a new app, so nothing needs restoring.
beforeEach(function (): void {
	edition(Edition::Pro);
	lettermintEmail(['token' => 'secret']);
	Craft::$app->env = 'testing';
	Craft::$app->language = 'en-US';
});

it('sends emails with the site as metadata', function (): void {
	$payload = sendForSite([], 'site-a', function (): void {
		Craft::$app->getSites()->getCurrentSite()->handle = 'shop';
	});

	expect($payload['metadata'])->toBe([
		'craft_site' => 'shop',
		'craft_language' => 'en-US',
		'craft_environment' => 'testing',
	]);
});

it('sends emails with the system message key and user as metadata', function (): void {
	$payload = sendForSite([], 'site-a', function (Message $message): void {
		Craft::$app->getSites()->getCurrentSite()->handle = 'shop';
		Craft::$app->language = 'de-AT';
		$message->setTo(recipient());
		setMessageKey('custom_email');
	});

	expect($payload['metadata'])->toBe([
		'craft_email' => 'custom_email',
		'craft_site' => 'shop',
		'craft_user' => '42',
		'craft_language' => 'de-AT',
		'craft_environment' => 'testing',
	]);
});

it('prefers metadata set on the message', function (): void {
	$payload = sendForSite([], 'site-a', function (Message $message): void {
		Craft::$app->getSites()->getCurrentSite()->handle = 'shop';
		$message->getSymfonyEmail()->getHeaders()->add(new MetadataHeader('craft_site', 'custom'));
		$message->getSymfonyEmail()->getHeaders()->add(new MetadataHeader('order_id', '1234'));
	});

	expect($payload['metadata'])->toMatchArray(['craft_site' => 'custom', 'order_id' => '1234']);
});

it('sends emails with the language they are sent in, not the preferred language of their user', function (): void {
	$payload = sendForSite([], 'site-a', function (Message $message): void {
		$message->setTo(recipient(language: 'de-AT'));
	});

	expect(assertArray($payload['metadata'])['craft_language'])->toBe('en-US');
});

it('leaves out a user the email is not sent to', function (): void {
	$payload = sendForSite([], 'site-a', function (Message $message): void {
		$message->variables = ['user' => recipient('someone@example.test')];
	});

	expect($payload['metadata'])->not->toHaveKey('craft_user');
});

it('leaves out a user without an email address', function (): void {
	$payload = sendForSite([], 'site-a', function (Message $message): void {
		$user = recipient();
		$user->email = null;

		$message->variables = ['user' => $user];
	});

	expect($payload['metadata'])->not->toHaveKey('craft_user');
});

it('matches the user regardless of the case of their email address', function (): void {
	$payload = sendForSite([], 'site-a', function (Message $message): void {
		$message->setTo(recipient('Recipient@Example.test'));
	});

	expect(assertArray($payload['metadata'])['craft_user'])->toBe('42');
});

it('leaves out an environment that is not set', function (): void {
	Craft::$app->env = null;

	$payload = sendForSite([], 'site-a');

	expect($payload['metadata'])->not->toHaveKey('craft_environment');
});

it('sends emails without metadata in the standard edition', function (): void {
	edition(Edition::Standard);
	lettermintEmail(['token' => 'secret']);

	setMessageKey('custom_email');

	$payload = sendForSite([], 'site-a');

	expect($payload)->not->toHaveKey('metadata');
});

it('follows an edition that Craft switches within a request', function (): void {
	plugin()->edition = Edition::Standard->value;

	setMessageKey('custom_email');

	expect(sendForSite([], 'site-a'))->not->toHaveKey('metadata');
});

it('sends emails without metadata when Lettermint is not the transport', function (): void {
	Craft::$app->getProjectConfig()->set('email', ['fromEmail' => SENDER_EMAIL, 'transportType' => Sendmail::class]);

	setMessageKey('custom_email');

	$payload = sendForSite([], 'site-a');

	expect($payload)->not->toHaveKey('metadata');
});

it('skips messages that are not Craft messages', function (): void {
	$message = (new SymfonyMessage())->setTo(RECIPIENT_EMAIL);

	Metadata::add(new MailEvent(['message' => $message]));

	expect(metadataKeys($message->getSymfonyEmail()))->toBe([]);
});

it('leaves out the site when there is none', function (): void {
	// No sites, as during install.
	$message = (new Message())->setTo(RECIPIENT_EMAIL);

	Metadata::add(new MailEvent(['message' => $message]));

	expect(metadataKeys($message->getSymfonyEmail()))->toBe(['craft_language', 'craft_environment']);
});

it('leaves out an environment that is empty', function (): void {
	Craft::$app->env = '';

	$payload = sendForSite([], 'site-a');

	expect($payload['metadata'])->not->toHaveKey('craft_environment');
});

it('matches the user among the copies and blind copies', function (string $copy): void {
	$payload = sendForSite([], 'site-a', function (Message $message) use ($copy): void {
		$message->setTo('someone@example.test');
		$copy === 'cc' ? $message->setCc(RECIPIENT_EMAIL) : $message->setBcc(RECIPIENT_EMAIL);
		$message->variables = ['user' => recipient()];
	});

	expect(assertArray($payload['metadata'])['craft_user'])->toBe('42');
})->with(['copy' => ['cc'], 'blind copy' => ['bcc']]);

it('leaves out a user that is not a user', function (): void {
	$payload = sendForSite([], 'site-a', function (Message $message): void {
		$message->variables = ['user' => RECIPIENT_EMAIL];
	});

	expect($payload['metadata'])->not->toHaveKey('craft_user');
});
