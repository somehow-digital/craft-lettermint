<?php

declare(strict_types=1);

use craft\helpers\MailerHelper;
use craft\models\Site;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\enums\Edition;
use SomehowDigital\Craft\Lettermint\mail\SmtpOptions;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\SiteSettings;
use SomehowDigital\Craft\Lettermint\models\Tags;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\RawMessage;

const PRODUCTION_TAG = 'environment=production';

function smtpEvent(RawMessage $message): MessageEvent
{
	return new MessageEvent($message, new Envelope(new Address(SENDER_EMAIL), [new Address(RECIPIENT_EMAIL)]), 'lettermint');
}

function setSmtpSite(?string $uid): void
{
	if ($uid !== null) {
		Craft::$app->getSites()->setCurrentSite(new Site(['uid' => $uid, 'language' => 'en-US']));
	}
}

/**
 * The route and the tags a message carries as headers.
 *
 * @return array{route:?string,tags:list<string>}
 */
function smtpOptions(Email $email): array
{
	$tags = [];

	foreach ($email->getHeaders()->all('X-LM-Tags') as $header) {
		assert($header instanceof HeaderInterface);
		$tags[] = $header->getBodyAsString();
	}

	return ['route' => $email->getHeaders()->get('X-Lettermint-Route')?->getBodyAsString(), 'tags' => $tags];
}

/**
 * @return array{route:?string,tags:list<string>}
 */
function listenerOptions(Settings $settings, ?string $siteUid = null, ?Email $email = null): array
{
	$email ??= new Email();
	setSmtpSite($siteUid);

	(new SmtpOptions($settings))(smtpEvent($email));

	return smtpOptions($email);
}

/**
 * Dispatches the message event of the transport an adapter defines, like sending does before it connects.
 *
 * @param array<string,mixed> $settings
 * @return array{route:?string,tags:list<string>}
 */
function adapterOptions(array $settings, ?string $siteUid = null): array
{
	$transport = MailerHelper::createTransportAdapter(Adapter::class, ['token' => 'secret', 'transport' => 'smtp'] + $settings)->defineTransport();
	$dispatcher = (new ReflectionProperty(AbstractTransport::class, 'dispatcher'))->getValue($transport);
	assert($dispatcher instanceof EventDispatcher);

	$email = new Email();
	setSmtpSite($siteUid);

	$dispatcher->dispatch(smtpEvent($email));

	return smtpOptions($email);
}

it('sends the route and the tags as headers', function (): void {
	expect(listenerOptions(new Settings(route: 'outgoing', tags: new Tags(['environment' => 'production', 'app' => 'shop']))))
		->toBe(['route' => 'outgoing', 'tags' => [PRODUCTION_TAG, 'app=shop']]);
});

it('sends no headers without a route or tags', function (): void {
	expect(listenerOptions(new Settings()))->toBe(['route' => null, 'tags' => []]);
});

it('prefers the route of the site and adds its tags', function (): void {
	$settings = new Settings(
		route: 'outgoing',
		tags: new Tags(['site' => 'default', 'app' => 'shop']),
		sites: ['site-a' => new SiteSettings(route: 'shop-a', tags: new Tags(['site' => 'a']))],
	);

	expect(listenerOptions($settings, 'site-a'))->toBe(['route' => 'shop-a', 'tags' => ['site=a', 'app=shop']]);
});

it('keeps the route and the tags set on the message', function (): void {
	$email = new Email();
	$email->getHeaders()->addTextHeader('X-Lettermint-Route', 'custom');
	$email->getHeaders()->addTextHeader('X-LM-Tags', 'custom=yes');

	expect(listenerOptions(new Settings(route: 'outgoing', tags: new Tags(['app' => 'shop'])), email: $email))
		->toBe(['route' => 'custom', 'tags' => ['custom=yes']]);
});

it('skips messages that are not emails', function (): void {
	$message = new RawMessage('Hello');

	(new SmtpOptions(new Settings(route: 'outgoing')))(smtpEvent($message));

	expect($message->toString())->toBe('Hello');
});

describe('through the adapter', function (): void {
	it('sends the route and the tags of the adapter', function (): void {
		expect(adapterOptions(['route' => 'outgoing', 'tags' => PRODUCTION_TAG]))
			->toBe(['route' => 'outgoing', 'tags' => [PRODUCTION_TAG]]);
	});

	it('sends the route and the tags of the site in the pro edition', function (): void {
		edition(Edition::Pro);

		expect(adapterOptions([
			'route' => 'outgoing',
			'tags' => PRODUCTION_TAG . ', site=default',
			'sites' => ['site-a' => ['route' => 'shop-a', 'tags' => 'site=a']],
		], 'site-a'))->toBe(['route' => 'shop-a', 'tags' => [PRODUCTION_TAG, 'site=a']]);
	});

	it('sends the route and the tags of the adapter for sites without their own', function (): void {
		edition(Edition::Pro);

		expect(adapterOptions([
			'route' => 'outgoing',
			'tags' => PRODUCTION_TAG,
			'sites' => ['site-a' => ['route' => 'shop-a']],
		], 'site-b'))->toBe(['route' => 'outgoing', 'tags' => [PRODUCTION_TAG]]);
	});

	it('ignores the sites in the lite edition', function (): void {
		expect(adapterOptions([
			'route' => 'outgoing',
			'tags' => PRODUCTION_TAG,
			'sites' => ['site-a' => ['route' => 'shop-a', 'tags' => 'site=a']],
		], 'site-a'))->toBe(['route' => 'outgoing', 'tags' => [PRODUCTION_TAG]]);
	});

	it('resolves environment variables in the route and the tags', function (): void {
		$this->env(['LETTERMINT_ROUTE' => 'env-route', 'LETTERMINT_TAGS' => 'environment=env']);

		expect(adapterOptions(['route' => '$LETTERMINT_ROUTE', 'tags' => '$LETTERMINT_TAGS']))
			->toBe(['route' => 'env-route', 'tags' => ['environment=env']]);
	});

	it('sends no route or tags header without either', function (): void {
		expect(adapterOptions([]))->toBe(['route' => null, 'tags' => []]);
	});
});
