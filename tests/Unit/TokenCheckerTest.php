<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SomehowDigital\Craft\Lettermint\data\TokenStatus;
use SomehowDigital\Craft\Lettermint\TokenChecker;

it('pings Lettermint with the token', function (): void {
	$requests = $this->http(new Response(200, [], 'pong'));

	expect(TokenChecker::check('secret'))->toBe(TokenStatus::Valid);
	expect($requests)->toHaveCount(1);

	$request = $requests->getArrayCopy()[0];

	expect($request->getMethod())->toBe('GET');
	expect((string) $request->getUri())->toBe('https://api.lettermint.co/v1/ping');
	expect($request->getHeaderLine('x-lettermint-token'))->toBe('secret');
});

it('reports a rejected token as invalid', function (): void {
	$this->http(jsonResponse(['message' => 'Unauthenticated.'], 401));

	expect(TokenChecker::check('secret'))->toBe(TokenStatus::Invalid);
});

it('reports a malformed token as invalid without a request', function (string $token): void {
	$requests = $this->http(new Response(200, [], 'pong'));

	expect(TokenChecker::check($token))->toBe(TokenStatus::Invalid);
	expect($requests)->toHaveCount(0);
})->with(["sec\nret", 'sec ret']);

it('reports other responses as unknown', function (int $code): void {
	$this->http(new Response($code));

	expect(TokenChecker::check('secret'))->toBe(TokenStatus::Unknown);
})->with([302, 403, 429, 500, 503]);

it('reports an unreachable Lettermint as unknown', function (): void {
	$this->http(static fn(RequestInterface $request): never => throw new ConnectException('Timeout.', $request));

	expect(TokenChecker::check('secret'))->toBe(TokenStatus::Unknown);
});

it('skips the check without a token', function (): void {
	expect(TokenChecker::check(null))->toBeNull();
	expect(TokenChecker::check(''))->toBeNull();
});
