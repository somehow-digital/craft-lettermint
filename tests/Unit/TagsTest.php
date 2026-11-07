<?php

declare(strict_types=1);

use SomehowDigital\Craft\Lettermint\Tag;
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
	expect(Tags::parse('name=a=b')->toArray())->toBe([['name' => 'name', 'value' => 'a=b']]);
});

it('keeps the last tag of each name, in the order the names first appear', function (): void {
	$tags = new Tags(new Tag('a', '1'), new Tag('b', '2'), new Tag('a', '3'));

	expect($tags->toArray())->toBe([['name' => 'a', 'value' => '3'], ['name' => 'b', 'value' => '2']]);
	expect($tags)->toHaveCount(2);
});

it('merges tags, the other tags replacing the ones with the same name', function (): void {
	$merged = Tags::parse('environment=production, site=default')->merge(Tags::parse('site=a, shop=main'));

	expect($merged->toArray())->toBe([
		['name' => 'environment', 'value' => 'production'],
		['name' => 'site', 'value' => 'a'],
		['name' => 'shop', 'value' => 'main'],
	]);
});

it('is empty without tags', function (): void {
	expect((new Tags())->isEmpty())->toBeTrue();
	expect(Tags::parse('broken')->isEmpty())->toBeTrue();
	expect(Tags::parse('name=value')->isEmpty())->toBeFalse();
});
