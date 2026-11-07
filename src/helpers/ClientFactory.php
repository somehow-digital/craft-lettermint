<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\helpers;

use Craft;
use GuzzleHttp\ClientInterface;
use Lettermint\Lettermint;
use Lettermint\SymfonyMailer\Transport\LettermintApiTransport;
use SensitiveParameter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Creates the clients for requests to Lettermint, with the HTTP settings of Craft.
 *
 * The clients of the SDK use Guzzle, whose client can be replaced through the container. The transport uses the HTTP
 * client of Symfony, which only takes the HTTP proxy of Craft, as `config/guzzle.php` holds options of Guzzle.
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

	/**
	 * The transport that sends the emails, authenticated with a project (sending) token.
	 *
	 * @param array<string,mixed> $options The default route and tags of the messages.
	 */
	public static function transport(
		#[SensitiveParameter]
		string $token,
		?EventDispatcherInterface $dispatcher = null,
		array $options = [],
	): LettermintApiTransport {
		// Without the proxy of Craft, the client takes the one of the environment, as the client of Craft does.
		$client = HttpClient::create(['proxy' => Env::nonEmpty(Craft::$app->getConfig()->getGeneral()->httpProxy)]);

		return new LettermintApiTransport(projectToken: $token, client: $client, dispatcher: $dispatcher, options: $options);
	}

	private static function http(): ClientInterface
	{
		if (Craft::$container->has(ClientInterface::class)) {
			return Craft::$container->get(ClientInterface::class);
		}

		return Craft::createGuzzleClient();
	}
}
