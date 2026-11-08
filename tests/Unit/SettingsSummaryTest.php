<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\SettingsSummary;
use SomehowDigital\Craft\Lettermint\models\SiteSettings;
use SomehowDigital\Craft\Lettermint\models\Tags;

it('has the settings without tokens and secrets', function (): void {
	$summary = SettingsSummary::from(new Settings(
		token: 'secret',
		route: 'outgoing',
		tags: new Tags(['env' => 'production']),
		teamToken: 'team-secret',
		projectId: 'project-1',
		webhookSecret: 'webhook-secret',
		healthToken: 'health-secret',
		senderEmail: 'hello@acme.test',
	), []);

	expect($summary->senderEmail)->toBe('hello@acme.test');
	expect($summary->route)->toBe('outgoing');
	expect($summary->tags->tags)->toBe(['env' => 'production']);
	expect($summary->webhooks)->toBeTrue();
	expect($summary->health)->toBeTrue();
	expect(serialize($summary))->not->toContain('secret');
});

it('has the endpoints off without their secrets', function (): void {
	$summary = SettingsSummary::from(new Settings(), []);

	expect($summary->route)->toBeNull();
	expect($summary->webhooks)->toBeFalse();
	expect($summary->health)->toBeFalse();
});

it('has the email settings, route and tags each site sends with', function (): void {
	$summary = SettingsSummary::from(new Settings(
		route: 'outgoing',
		tags: new Tags(['env' => 'production', 'site' => 'default']),
		sites: ['site-b' => new SiteSettings(
			route: 'archive',
			tags: new Tags(['site' => 'archive']),
			senderEmail: 'archive@acme.test',
			senderName: 'Archive',
			replyToEmail: 'reply@archive.test',
			template: '_emails/archive',
		)],
		senderEmail: 'hello@acme.test',
		senderName: 'Acme',
		replyToEmail: 'reply@acme.test',
		template: '_emails/default',
	), ['site-a' => 'Shop', 'site-b' => 'Archive']);

	expect($summary->senderName)->toBe('Acme');
	expect($summary->replyToEmail)->toBe('reply@acme.test');
	expect($summary->template)->toBe('_emails/default');

	[$shop, $archive] = $summary->sites;

	expect($shop->name)->toBe('Shop');
	expect([$shop->senderEmail, $shop->senderName, $shop->replyToEmail, $shop->template, $shop->route])
		->toBe(['hello@acme.test', 'Acme', 'reply@acme.test', '_emails/default', 'outgoing']);
	expect($shop->tags->tags)->toBe(['env' => 'production', 'site' => 'default']);
	expect($shop->own)->toBe([]);
	expect($shop->isOwn('route'))->toBeFalse();

	expect($archive->name)->toBe('Archive');
	expect([$archive->senderEmail, $archive->senderName, $archive->replyToEmail, $archive->template, $archive->route])
		->toBe(['archive@acme.test', 'Archive', 'reply@archive.test', '_emails/archive', 'archive']);
	expect($archive->tags->tags)->toBe(['env' => 'production', 'site' => 'archive']);
	expect($archive->own)->toBe(['senderEmail', 'senderName', 'replyToEmail', 'template', 'route', 'tags']);
	expect($archive->isOwn('route'))->toBeTrue();
});
