<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\helpers\Env;
use SomehowDigital\Craft\Lettermint\helpers\Sites;

it('skips invalid sites and keeps the ones after them', function (): void {
	expect(Sites::normalize([0 => ['route' => 'shop-0'], 'site-a' => 'shop', 'site-b' => ['route' => 'shop-b']]))
		->toBe(['site-b' => ['route' => 'shop-b']]);
});

it('takes values that are only whitespace as empty', function (): void {
	expect(Env::nonEmpty('  '))->toBeNull();
	expect(Env::nonEmpty(' value '))->toBe('value');
});
