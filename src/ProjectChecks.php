<?php

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\helpers\App;

/**
 * Checks the email settings against the Lettermint project of the token.
 */
final class ProjectChecks
{
	/**
	 * @param array<mixed> $project The project, with its routes and domains.
	 * @param array<mixed> $domains The domains of the team, with their status.
	 */
	public function __construct(
		private readonly Adapter $adapter,
		private readonly array $project,
		private readonly array $domains,
	) {}

	/**
	 * @return list<array{id:string,status:string,text:string}>
	 */
	public function all(): array
	{
		return [
			$this->delivery(),
			...$this->sender(),
			...$this->routes(),
		];
	}

	/**
	 * @return array{id:string,status:string,text:string}
	 */
	private function delivery(): array
	{
		return ($this->project['delivery_mode'] ?? null) === 'sandbox'
			? Check::create('delivery', Check::WARNING, 'The project is in sandbox mode, so emails are not delivered to their recipients.')
			: Check::create('delivery', Check::OK, 'The project is live.');
	}

	/**
	 * Checks the domain of the system email address, which Lettermint only sends from when it is a domain of the project.
	 *
	 * @return list<array{id:string,status:string,text:string}>
	 */
	private function sender(): array
	{
		$email = App::parseEnv(App::mailSettings()->fromEmail);

		if (!is_string($email) || false === $at = strrpos($email, '@')) {
			return [];
		}

		return [$this->senderDomain(strtolower(substr($email, $at + 1)))];
	}

	/**
	 * @return array{id:string,status:string,text:string}
	 */
	private function senderDomain(string $domain): array
	{
		$params = ['domain' => $domain];
		$match = self::find($this->project['domains'] ?? null, static fn(array $item) => is_string($item['domain'] ?? null) && strtolower($item['domain']) === $domain);

		if ($match === null) {
			return Check::create('sender', Check::ERROR, 'The domain “{domain}” of the system email address is not a domain of the project.', $params);
		}

		// The domains of a project leave out their status, which the domains of the team have.
		$status = self::find($this->domains, static fn(array $item) => ($item['id'] ?? null) === ($match['id'] ?? false))['status'] ?? null;

		return $status !== null && $status !== 'verified'
			? Check::create('sender', Check::WARNING, 'The domain “{domain}” of the system email address is not fully verified.', $params)
			: Check::create('sender', Check::OK, 'The domain “{domain}” of the system email address belongs to the project.', $params);
	}

	/**
	 * Checks the route slugs of the adapter and the sites, which must be outgoing routes of the project.
	 *
	 * @return list<array{id:string,status:string,text:string}>
	 */
	private function routes(): array
	{
		if (!is_array($this->project['routes'] ?? null)) {
			return [];
		}

		$routes = array_values(array_filter(
			$this->project['routes'],
			static fn(mixed $route) => is_array($route) && ($route['route_type'] ?? null) !== 'inbound',
		));
		$slugs = array_fill_keys(array_filter(array_column($routes, 'slug'), is_string(...)), true);
		// The last default route wins, should a project have more than one.
		$default = self::find(array_reverse($routes), static fn(array $route) => ($route['is_default'] ?? false) === true);
		$check = $this->adapterRoute($slugs, $default);

		return [...($check !== null ? [$check] : []), ...$this->siteRoutes($slugs)];
	}

	/**
	 * Checks the route of the adapter, or names the default route of the project when the adapter has none.
	 *
	 * @param array<string,true> $slugs The slugs of the outgoing routes of the project.
	 * @param array<mixed>|null $default The default route of the project.
	 * @return array{id:string,status:string,text:string}|null
	 */
	private function adapterRoute(array $slugs, ?array $default): ?array
	{
		$route = $this->adapter->getRoute();

		if ($route === null) {
			return $default !== null
				? Check::create('route', Check::OK, 'Emails use the default route “{route}” of the project.', ['route' => $default['slug'] ?? ''])
				: null;
		}

		return isset($slugs[$route])
			? Check::create('route', Check::OK, 'The route “{route}” belongs to the project.', ['route' => $route])
			: Check::create('route', Check::ERROR, 'The route “{route}” is not an outgoing route of the project.', ['route' => $route]);
	}

	/**
	 * Checks the routes of the sites that set their own.
	 *
	 * @param array<string,true> $slugs The slugs of the outgoing routes of the project.
	 * @return list<array{id:string,status:string,text:string}>
	 */
	private function siteRoutes(array $slugs): array
	{
		$checks = [];

		foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
			if ($site->uid === null || null === $route = $this->adapter->getSiteRoute($site->uid)) {
				continue;
			}

			$id = 'route.' . ($site->handle ?? $site->uid);
			$params = ['route' => $route, 'site' => $site->getName()];
			$checks[] = isset($slugs[$route])
				? Check::create($id, Check::OK, 'The route “{route}” of {site} belongs to the project.', $params)
				: Check::create($id, Check::ERROR, 'The route “{route}” of {site} is not an outgoing route of the project.', $params);
		}

		return $checks;
	}

	/**
	 * The first item of a list that matches, or null when none does, or when it is not a list.
	 *
	 * @param callable(array<mixed>):bool $matches
	 * @return array<mixed>|null
	 */
	private static function find(mixed $items, callable $matches): ?array
	{
		if (!is_array($items)) {
			return null;
		}

		foreach ($items as $item) {
			if (is_array($item) && $matches($item)) {
				return $item;
			}
		}

		return null;
	}
}
