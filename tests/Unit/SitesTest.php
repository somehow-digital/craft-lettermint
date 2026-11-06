<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\Env;
use SomehowDigital\Craft\Lettermint\Sites;

it('skips invalid sites and keeps the ones after them', function (): void {
	expect(Sites::normalize([0 => ['route' => 'shop-0'], 'site-a' => 'shop', 'site-b' => ['route' => 'shop-b']]))
		->toBe(['site-b' => ['route' => 'shop-b']]);
});

it('names the sites of tag errors, also the disabled ones', function (): void {
	addSite('site-a', 'archive', 'Archive', enabled: false);

	expect(Sites::tagErrors(['site-a' => ['tags' => 'broken'], 'site-b' => ['tags' => 'broken']], []))->toBe([
		'Archive: “broken” is not a name=value pair.',
		'site-b: “broken” is not a name=value pair.',
	]);
});

it('takes values that are only whitespace as empty', function (): void {
	expect(Env::nonEmpty('  '))->toBeNull();
	expect(Env::nonEmpty(' value '))->toBe('value');
});
