<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\Adapter;

it('names the sites of tag errors, also the disabled ones', function (): void {
	addSite('site-a', 'archive', 'Archive', enabled: false);

	$adapter = new Adapter(['token' => 'secret', 'sites' => ['site-a' => ['tags' => 'broken'], 'site-b' => ['tags' => 'broken']]]);
	$adapter->validate(['sites']);

	expect($adapter->getErrors('sites'))->toBe([
		'Archive: “broken” is not a name=value pair.',
		'site-b: “broken” is not a name=value pair.',
	]);
});
