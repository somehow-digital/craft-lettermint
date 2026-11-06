<?php

declare(strict_types=1);

use craft\elements\User;
use craft\helpers\MailerHelper;
use craft\mail\Mailer;
use craft\mail\Message;
use craft\models\Site;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Plugin;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Transport\NullTransport;
use SomehowDigital\Craft\Lettermint\Metadata;
use yii\base\Event;
use yii\mail\MailEvent;

/**
 * A user to send messages to, with a preferred language that is not loaded from the database.
 */
function recipient(string $email = RECIPIENT_EMAIL, ?string $language = null): User
{
	$user = new class extends User {
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
 * Sets the system message key of the messages once Craft prepared them, as Craft renders a system message for a key
 * set before.
 */
function setMessageKey(string $key): void
{
	// The test case removes the handlers of all events after each test.
	Event::on(Mailer::class, Mailer::EVENT_BEFORE_SEND, static function (MailEvent $event) use ($key): void {
		assertInstance($event->message, Message::class)->key = $key;
	}, append: false);
}

// The test case boots a new app for each test, so the environment and the language are not restored.
beforeEach(function (): void {
	plugin()->edition = Plugin::EDITION_PRO;
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
	plugin()->edition = Plugin::EDITION_STANDARD;

	setMessageKey('custom_email');

	$payload = sendForSite([], 'site-a');

	expect($payload)->not->toHaveKey('metadata');
});

it('forgets the message once it is sent, even when sending fails', function (): void {
	$payloads = [];

	$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$payloads): JsonMockResponse {
		$payloads[] = jsonArray($options['body'] ?? null);

		return count($payloads) === 1
			? new JsonMockResponse(['message' => 'Server error'], ['http_code' => 500])
			: new JsonMockResponse(['message_id' => 'message-123'], ['http_code' => 202]);
	});

	Craft::$app->getSites()->setCurrentSite(new Site(['uid' => 'site-a', 'language' => 'en-US']));

	$transport = MailerHelper::createTransportAdapter(Adapter::class, ['token' => 'secret'])->defineTransport();
	(new ReflectionProperty(AbstractHttpTransport::class, 'client'))->setValue($transport, $client);

	$message = assertInstance((new Mailer(['messageClass' => Message::class, 'transport' => $transport]))->compose(), Message::class)
		->setFrom(SENDER_EMAIL)
		->setTo(recipient())
		->setSubject('Hello')
		->setTextBody('Hello there');

	// Craft catches the failure, without the mailer releasing the message.
	expect($message->send())->toBeFalse();

	// An email sent through the transport directly has no Craft message.
	$transport->send((new Email())->from(SENDER_EMAIL)->to('other@example.test')->subject('Hi')->text('Hi'));

	expect($payloads[0]['metadata'])->toHaveKey('craft_user', '42');
	expect($payloads[1]['metadata'])->not->toHaveKey('craft_user');
});

it('skips messages that are not emails', function (): void {
	$message = new RawMessage('Hello');

	(new Metadata())(new MessageEvent($message, new Envelope(new Address(SENDER_EMAIL), [new Address(RECIPIENT_EMAIL)]), 'lettermint'));

	expect($message->toString())->toBe('Hello');
});

it('leaves out the site when there is none', function (): void {
	expect(sendThroughTransport()['metadata'])->not->toHaveKey('craft_site');
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

it('forgets a message that another transport sent', function (): void {
	Craft::$app->getSites()->setCurrentSite(new Site(['uid' => 'site-a', 'language' => 'en-US']));

	$sent = assertInstance((new Mailer(['messageClass' => Message::class, 'transport' => new NullTransport()]))->compose(), Message::class)
		->setFrom(SENDER_EMAIL)
		->setTo(recipient())
		->setSubject('Hello')
		->setTextBody('Hello there')
		->send();

	expect($sent)->toBeTrue();
	// An email sent through the transport directly has no Craft message, though it goes to the same recipient.
	expect(sendThroughTransport()['metadata'])->not->toHaveKey('craft_user');
});
