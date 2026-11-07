<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\helpers\App;
use SomehowDigital\Craft\Lettermint\data\Domain;
use SomehowDigital\Craft\Lettermint\data\Project;
use SomehowDigital\Craft\Lettermint\data\Route;

/**
 * Checks the email settings against the Lettermint project of the token.
 */
final readonly class ProjectChecks
{
	/**
	 * @param Project $project The project, with its routes, and its domains with their status.
	 */
	public function __construct(
		private Adapter $adapter,
		private Project $project,
	) {}

	/**
	 * @return list<Check>
	 */
	public function all(): array
	{
		return [
			$this->delivery(),
			...$this->sender(),
			...$this->routes(),
		];
	}

	private function delivery(): Check
	{
		return $this->project->deliveryMode === 'sandbox'
			? Check::warning('delivery', 'The project is in sandbox mode, so emails are not delivered to their recipients.')
			: Check::ok('delivery', 'The project is live.');
	}

	/**
	 * Checks the domain of the system email address, which Lettermint only sends from when it is a domain of the project.
	 *
	 * @return list<Check>
	 */
	private function sender(): array
	{
		$email = App::parseEnv(App::mailSettings()->fromEmail);

		if (!is_string($email) || false === $at = strrpos($email, '@')) {
			return [];
		}

		return [$this->senderDomain(strtolower(substr($email, $at + 1)))];
	}

	private function senderDomain(string $domain): Check
	{
		$params = ['domain' => $domain];
		$match = $this->find($this->project->domains, static fn(Domain $item): bool => $item->domain !== null && strtolower($item->domain) === $domain);

		if ($match === null) {
			return Check::error('sender', 'The domain “{domain}” of the system email address is not a domain of the project.', $params);
		}

		// A domain without a status was not listed, so nothing is known about its verification.
		return $match->status !== null && $match->status !== Domain::STATUS_VERIFIED
			? Check::warning('sender', 'The domain “{domain}” of the system email address is not fully verified.', $params)
			: Check::ok('sender', 'The domain “{domain}” of the system email address belongs to the project.', $params);
	}

	/**
	 * Checks the route slugs of the adapter and the sites, which must be outgoing routes of the project.
	 *
	 * @return list<Check>
	 */
	private function routes(): array
	{
		$routes = array_filter($this->project->routes, static fn(Route $route): bool => $route->outgoing());
		// The routes are compared strictly, so routes without a slug match none.
		$slugs = array_column($routes, 'slug');
		// The last default route wins, should a project have more than one.
		$default = $this->find(array_reverse($routes), static fn(Route $route): bool => $route->default);
		return [...$this->adapterRoute($slugs, $default), ...$this->siteRoutes($slugs)];
	}

	/**
	 * Checks the route of the adapter, or names the default route of the project when the adapter has none, so
	 * without either, there is no check.
	 *
	 * @param array<?string> $slugs The slugs of the outgoing routes of the project.
	 * @param Route|null $default The default route of the project.
	 * @return list<Check>
	 */
	private function adapterRoute(array $slugs, ?Route $default): array
	{
		$route = $this->adapter->getRoute();

		if ($route === null) {
			return $default instanceof Route
				? [Check::ok('route', 'Emails use the default route “{route}” of the project.', ['route' => $default->slug ?? ''])]
				: [];
		}

		return [in_array($route, $slugs, true)
			? Check::ok('route', 'The route “{route}” belongs to the project.', ['route' => $route])
			: Check::error('route', 'The route “{route}” is not an outgoing route of the project.', ['route' => $route])];
	}

	/**
	 * Checks the routes of the sites that set their own.
	 *
	 * @param array<?string> $slugs The slugs of the outgoing routes of the project.
	 * @return list<Check>
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
			$checks[] = in_array($route, $slugs, true)
				? Check::ok($id, 'The route “{route}” of {site} belongs to the project.', $params)
				: Check::error($id, 'The route “{route}” of {site} is not an outgoing route of the project.', $params);
		}

		return $checks;
	}

	/**
	 * The first item of a list that matches, or null when none does.
	 *
	 * @template T
	 * @param array<T> $items
	 * @param callable(T):bool $matches
	 * @return T|null
	 */
	private function find(array $items, callable $matches): mixed
	{
		foreach ($items as $item) {
			if ($matches($item)) {
				return $item;
			}
		}

		return null;
	}
}
