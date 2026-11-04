<?php

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\helpers\App;

/**
 * Checks the email settings against the Lettermint project of the token.
 */
final class ProjectChecks
{
	public const OK = 'ok';
	public const WARNING = 'warning';
	public const ERROR = 'error';

	/**
	 * @param array<mixed> $project The project, with its routes and domains.
	 * @param array<mixed> $domains The domains of the team, with their status.
	 */
	public function __construct(
		private readonly Adapter $adapter,
		private readonly array $project,
		private readonly array $domains,
	) {
	}

	/**
	 * @return list<array{status:string,text:string}>
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
	 * @return array{status:string,text:string}
	 */
	private function delivery(): array
	{
		return ($this->project['delivery_mode'] ?? null) === 'sandbox'
			? self::check(self::WARNING, 'The project is in sandbox mode, so emails are not delivered to their recipients.')
			: self::check(self::OK, 'The project is live.');
	}

	/**
	 * Checks the domain of the system email address, which Lettermint only sends from when it is a domain of the project.
	 *
	 * @return list<array{status:string,text:string}>
	 */
	private function sender(): array
	{
		$email = App::parseEnv(App::mailSettings()->fromEmail);

		if (!is_string($email) || !str_contains($email, '@')) {
			return [];
		}

		return [$this->senderDomain(strtolower(substr($email, strrpos($email, '@') + 1)))];
	}

	/**
	 * @return array{status:string,text:string}
	 */
	private function senderDomain(string $domain): array
	{
		$params = ['domain' => $domain];
		$match = self::find($this->project['domains'] ?? [], static fn (array $item) => strtolower((string) ($item['domain'] ?? '')) === $domain);

		if ($match === null) {
			return self::check(self::ERROR, 'The domain “{domain}” of the system email address is not a domain of the project.', $params);
		}

		// The domains of a project leave out their status, which the domains of the team have.
		$status = self::find($this->domains, static fn (array $item) => ($item['id'] ?? null) === ($match['id'] ?? false))['status'] ?? null;

		return $status !== null && $status !== 'verified'
			? self::check(self::WARNING, 'The domain “{domain}” of the system email address is not fully verified.', $params)
			: self::check(self::OK, 'The domain “{domain}” of the system email address belongs to the project.', $params);
	}

	/**
	 * Checks the route slugs of the adapter and the sites, which must be outgoing routes of the project.
	 *
	 * @return list<array{status:string,text:string}>
	 */
	private function routes(): array
	{
		if (!is_array($this->project['routes'] ?? null)) {
			return [];
		}

		$slugs = [];
		$default = null;

		foreach ($this->project['routes'] as $route) {
			if (($route['route_type'] ?? null) === 'inbound') {
				continue;
			}

			$slugs[$route['slug'] ?? ''] = true;

			if ($route['is_default'] ?? false) {
				$default = $route;
			}
		}

		$checks = [];

		if (null !== $route = $this->adapter->getRoute()) {
			$checks[] = isset($slugs[$route])
				? self::check(self::OK, 'The route “{route}” belongs to the project.', ['route' => $route])
				: self::check(self::ERROR, 'The route “{route}” is not an outgoing route of the project.', ['route' => $route]);
		} elseif ($default !== null) {
			$checks[] = self::check(self::OK, 'Emails use the default route “{route}” of the project.', ['route' => $default['slug'] ?? '']);
		}

		foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
			if (null === $route = $this->adapter->getSiteRoute($site->uid)) {
				continue;
			}

			$params = ['route' => $route, 'site' => $site->getName()];
			$checks[] = isset($slugs[$route])
				? self::check(self::OK, 'The route “{route}” of {site} belongs to the project.', $params)
				: self::check(self::ERROR, 'The route “{route}” of {site} is not an outgoing route of the project.', $params);
		}

		return $checks;
	}

	/**
	 * The first item of a list that matches, or null when none does.
	 *
	 * @param array<mixed> $items
	 * @param callable(array<mixed>):bool $matches
	 * @return array<mixed>|null
	 */
	private static function find(array $items, callable $matches): ?array
	{
		foreach ($items as $item) {
			if (is_array($item) && $matches($item)) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array{status:string,text:string}
	 */
	private static function check(string $status, string $message, array $params = []): array
	{
		return ['status' => $status, 'text' => Craft::t('lettermint', $message, $params)];
	}
}
