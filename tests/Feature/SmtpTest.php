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
use Symfony\Component\Mime\RawMessage;

/**
 * @return list<string> The headers as `Name: value` lines.
 */
function smtpHeaders(Settings $settings, ?string $siteUid = null, ?Email $email = null): array
{
	$email ??= new Email();

	if ($siteUid !== null) {
		Craft::$app->getSites()->setCurrentSite(new Site(['uid' => $siteUid, 'language' => 'en-US']));
	}

	(new SmtpOptions($settings))(new MessageEvent($email, new Envelope(new Address(SENDER_EMAIL), [new Address(RECIPIENT_EMAIL)]), 'lettermint'));

	return array_map(static fn(mixed $line): string => is_string($line) ? $line : '', array_values($email->getHeaders()->toArray()));
}

it('sends the route and the tags as headers', function (): void {
	$headers = smtpHeaders(new Settings(route: 'outgoing', tags: new Tags(['environment' => 'production', 'app' => 'shop'])));

	expect($headers)->toContain('X-Lettermint-Route: outgoing');
	expect(array_values(array_filter($headers, static fn(string $line): bool => str_starts_with($line, 'X-LM-Tags: '))))->toBe(['X-LM-Tags: environment=production', 'X-LM-Tags: app=shop']);
});

it('sends no headers without a route or tags', function (): void {
	expect(smtpHeaders(new Settings()))->toBe([]);
});

it('prefers the route of the site and adds its tags', function (): void {
	$headers = smtpHeaders(new Settings(
		route: 'outgoing',
		tags: new Tags(['site' => 'default', 'app' => 'shop']),
		sites: ['site-a' => new SiteSettings(route: 'shop-a', tags: new Tags(['site' => 'a']))],
	), 'site-a');

	expect($headers)->toContain('X-Lettermint-Route: shop-a');
	expect(array_values(array_filter($headers, static fn(string $line): bool => str_starts_with($line, 'X-LM-Tags: '))))->toBe(['X-LM-Tags: site=a', 'X-LM-Tags: app=shop']);
});

it('keeps the route and the tags set on the message', function (): void {
	$email = new Email();
	$email->getHeaders()->addTextHeader('X-Lettermint-Route', 'custom');
	$email->getHeaders()->addTextHeader('X-LM-Tags', 'custom=yes');

	$headers = smtpHeaders(new Settings(route: 'outgoing', tags: new Tags(['app' => 'shop'])), email: $email);

	expect($headers)->toContain('X-Lettermint-Route: custom');
	expect(array_values(array_filter($headers, static fn(string $line): bool => str_starts_with($line, 'X-LM-Tags: '))))->toBe(['X-LM-Tags: custom=yes']);
});

it('skips messages that are not emails', function (): void {
	$message = new RawMessage('Hello');

	(new SmtpOptions(new Settings(route: 'outgoing')))(new MessageEvent($message, new Envelope(new Address(SENDER_EMAIL), [new Address(RECIPIENT_EMAIL)]), 'lettermint'));

	expect($message->toString())->toBe('Hello');
});

/**
 * Dispatches the message event of the transport an adapter defines, like sending does before it connects.
 *
 * @param array<string,mixed> $settings
 * @return list<string> The headers as `Name: value` lines.
 */
function smtpAdapterHeaders(array $settings, ?string $siteUid = null): array
{
	$transport = MailerHelper::createTransportAdapter(Adapter::class, ['token' => 'secret', 'transport' => 'smtp'] + $settings)->defineTransport();
	$dispatcher = (new ReflectionProperty(AbstractTransport::class, 'dispatcher'))->getValue($transport);
	assert($dispatcher instanceof EventDispatcher);

	$email = new Email();

	if ($siteUid !== null) {
		Craft::$app->getSites()->setCurrentSite(new Site(['uid' => $siteUid, 'language' => 'en-US']));
	}

	$dispatcher->dispatch(new MessageEvent($email, new Envelope(new Address(SENDER_EMAIL), [new Address(RECIPIENT_EMAIL)]), 'lettermint'));

	return array_map(static fn(mixed $line): string => is_string($line) ? $line : '', array_values($email->getHeaders()->toArray()));
}

describe('through the adapter', function (): void {
	it('sends the route and the tags of the adapter', function (): void {
		$headers = smtpAdapterHeaders(['route' => 'outgoing', 'tags' => 'environment=production']);

		expect($headers)->toContain('X-Lettermint-Route: outgoing');
		expect($headers)->toContain('X-LM-Tags: environment=production');
	});

	it('sends the route and the tags of the site in the pro edition', function (): void {
		edition(Edition::Pro);

		$headers = smtpAdapterHeaders([
			'route' => 'outgoing',
			'tags' => 'environment=production, site=default',
			'sites' => ['site-a' => ['route' => 'shop-a', 'tags' => 'site=a']],
		], 'site-a');

		expect($headers)->toContain('X-Lettermint-Route: shop-a');
		expect($headers)->toContain('X-LM-Tags: environment=production');
		expect($headers)->toContain('X-LM-Tags: site=a');
		expect($headers)->not->toContain('X-LM-Tags: site=default');
	});

	it('sends the route and the tags of the adapter for sites without their own', function (): void {
		edition(Edition::Pro);

		$headers = smtpAdapterHeaders([
			'route' => 'outgoing',
			'tags' => 'environment=production',
			'sites' => ['site-a' => ['route' => 'shop-a']],
		], 'site-b');

		expect($headers)->toContain('X-Lettermint-Route: outgoing');
		expect($headers)->toContain('X-LM-Tags: environment=production');
	});

	it('ignores the sites in the standard edition', function (): void {
		$headers = smtpAdapterHeaders([
			'route' => 'outgoing',
			'tags' => 'environment=production',
			'sites' => ['site-a' => ['route' => 'shop-a', 'tags' => 'site=a']],
		], 'site-a');

		expect($headers)->toContain('X-Lettermint-Route: outgoing');
		expect($headers)->toContain('X-LM-Tags: environment=production');
		expect($headers)->not->toContain('X-LM-Tags: site=a');
	});

	it('resolves environment variables in the route and the tags', function (): void {
		$this->env(['LETTERMINT_ROUTE' => 'env-route', 'LETTERMINT_TAGS' => 'environment=env']);

		$headers = smtpAdapterHeaders(['route' => '$LETTERMINT_ROUTE', 'tags' => '$LETTERMINT_TAGS']);

		expect($headers)->toContain('X-Lettermint-Route: env-route');
		expect($headers)->toContain('X-LM-Tags: environment=env');
	});

	it('sends no route or tags header without either', function (): void {
		expect(smtpAdapterHeaders([]))->toBe([]);
	});
});
