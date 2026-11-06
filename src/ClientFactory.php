<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use SensitiveParameter;
use Craft;
use GuzzleHttp\ClientInterface;
use Lettermint\Lettermint;

/**
 * Creates clients of the SDK for requests to Lettermint other than sending, with the HTTP settings of Craft.
 *
 * The Guzzle client can be replaced through the container.
 *
 * @internal
 */
final class ClientFactory
{
	/**
	 * A client for the project API, authenticated with a project (sending) token.
	 */
	public static function project(#[SensitiveParameter] string $token, int $timeout): Lettermint
	{
		return new Lettermint(sendingToken: $token, timeout: $timeout, httpClient: self::http());
	}

	/**
	 * A client for the team API, authenticated with a team token.
	 */
	public static function team(#[SensitiveParameter] string $token, int $timeout): Lettermint
	{
		return new Lettermint(teamToken: $token, timeout: $timeout, httpClient: self::http());
	}

	private static function http(): ClientInterface
	{
		if (Craft::$container->has(ClientInterface::class)) {
			return Craft::$container->get(ClientInterface::class);
		}

		return Craft::createGuzzleClient();
	}
}
