<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\Tags;

it('names the tag of each error, and goes on after a tag without a value', function (): void {
	expect(Tags::errors('broken, bad name=value, name=bad value, __lettermint_id=1, name=ok, name=again'))->toBe([
		'“broken” is not a name=value pair.',
		'Tag name “bad name” must be 1 to 32 letters, digits, underscores or hyphens.',
		'Tag value “bad value” must be 1 to 64 letters, digits, underscores or hyphens.',
		'Tag name “__lettermint_id” is reserved.',
		'Tag name “name” is used more than once.',
		'Tag name “name” is used more than once.',
	]);
});

it('names the maximum of tags', function (): void {
	$tags = implode(',', array_map(static fn(int $i): string => "tag{$i}=value", range(1, Tags::MAX + 1)));

	expect(Tags::errors($tags))->toBe(['There can be at most 20 tags.']);
});

it('skips entries that are only whitespace', function (): void {
	expect(Tags::errors('name=value, ,'))->toBe([]);
});

it('takes everything after the first equals sign as the value', function (): void {
	expect(Tags::parse('name=a=b'))->toBe([['name' => 'name', 'value' => 'a=b']]);
});
