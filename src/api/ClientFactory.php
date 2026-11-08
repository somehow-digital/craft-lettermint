<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\api;

use Closure;
use GuzzleHttp\ClientInterface;
use Lettermint\Lettermint;
use SensitiveParameter;

/**
 * Creates the clients of the SDK for requests to Lettermint, with the HTTP client of Craft.
 *
 * The plugin creates it in the container with the HTTP client of Craft, which projects can replace there.
 *
 * @internal
 */
final readonly class ClientFactory
{
	/**
	 * @param Closure():ClientInterface $http Creates the HTTP client of each client of the SDK.
	 */
	public function __construct(
		private Closure $http,
	) {}

	/**
	 * A client for the project API, authenticated with a project (sending) token.
	 */
	public function project(#[SensitiveParameter] string $token, int $timeout): Lettermint
	{
		return new Lettermint(sendingToken: $token, timeout: $timeout, httpClient: ($this->http)());
	}

	/**
	 * A client for the team API, authenticated with a team token.
	 */
	public function team(#[SensitiveParameter] string $token, int $timeout): Lettermint
	{
		return new Lettermint(teamToken: $token, timeout: $timeout, httpClient: ($this->http)());
	}
}
