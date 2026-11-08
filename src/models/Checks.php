<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use craft\models\Site;
use SomehowDigital\Craft\Lettermint\enums\ApiError;
use SomehowDigital\Craft\Lettermint\enums\CheckStatus;
use SomehowDigital\Craft\Lettermint\enums\CheckType;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;

/**
 * Builds the checks shared by the health endpoint, utility and widget.
 *
 * The project token is always checked; the team token, usage and project only with team API results.
 * Everything is passed in, so nothing is read from Craft or Lettermint here.
 *
 * @internal
 */
final readonly class Checks
{
	/**
	 * Usage share of the plan volume that triggers a warning.
	 */
	public const USAGE_WARNING = 0.9;

	/**
	 * @param array<Site> $sites
	 */
	private function __construct(
		private Settings $settings,
		private ?TokenStatus $tokenStatus,
		private array $sites,
	) {}

	/**
	 * Stops after the team token once it's rejected.
	 *
	 * @param TokenStatus|null $tokenStatus Null without a project token.
	 * @param TeamApiData|null $api Null without a team token.
	 * @param array<Site> $sites Including disabled ones.
	 */
	public static function build(Settings $settings, ?TokenStatus $tokenStatus, ?TeamApiData $api, array $sites): CheckResult
	{
		return (new self($settings, $tokenStatus, $sites))->result($api);
	}

	private function result(?TeamApiData $api): CheckResult
	{
		$token = $this->token();

		if (!$api instanceof TeamApiData) {
			return new CheckResult($token);
		}

		$teamToken = $this->teamToken($api);

		if ($api->invalid()) {
			return new CheckResult($token, $teamToken);
		}

		return new CheckResult(
			token: $token,
			teamToken: $teamToken,
			usage: $this->usage($api),
			project: $this->projectError($api),
			projectChecks: $this->project($api->project?->data),
		);
	}

	private function token(): Check
	{
		return match ($this->tokenStatus) {
			TokenStatus::Valid => Check::ok(CheckType::Token, 'Lettermint accepts the project API token.'),
			TokenStatus::Invalid => Check::error(CheckType::Token, 'Lettermint rejects the project API token, or does not allow it from the IP address of this server.'),
			TokenStatus::Unknown => Check::error(CheckType::Token, 'Lettermint could not be reached.'),
			null => Check::error(CheckType::Token, 'The project API token is not set.'),
		};
	}

	/**
	 * Not needed for sending, so only warns.
	 */
	private function teamToken(TeamApiData $api): Check
	{
		return match ($api->teamTokenStatus()) {
			TokenStatus::Valid => Check::ok(CheckType::TeamToken, 'Lettermint accepts the team API token.'),
			TokenStatus::Invalid => Check::warning(CheckType::TeamToken, 'Lettermint rejects the team API token.'),
			TokenStatus::Unknown => Check::warning(CheckType::TeamToken, 'The team could not be loaded from Lettermint.'),
		};
	}

	private function usage(TeamApiData $api): ?Check
	{
		$usage = $api->usageSummary();

		if (!$usage instanceof UsageSummary) {
			return null;
		}

		$params = ['used' => $usage->used, 'included' => $usage->included];

		return match (true) {
			$usage->share === null => Check::ok(CheckType::Usage, '{used} emails were sent this period.', $params),
			$usage->share >= 1 => Check::warning(CheckType::Usage, 'The monthly volume of the plan is used up, with {used} of {included} emails.', $params),
			$usage->share >= self::USAGE_WARNING => Check::warning(CheckType::Usage, 'The monthly volume of the plan is nearly used up, with {used} of {included} emails.', $params),
			default => Check::ok(CheckType::Usage, '{used} of {included} emails of the plan were sent this period.', $params),
		};
	}

	/**
	 * Only warns, as emails are still sent.
	 */
	private function projectError(TeamApiData $api): ?Check
	{
		return match ($api->project?->error) {
			null => null,
			ApiError::Missing => Check::warning(CheckType::Project, 'The project “{project}” is not a project of the team.', ['project' => $this->settings->projectId]),
			default => Check::warning(CheckType::Project, 'The project could not be loaded from Lettermint.'),
		};
	}

	/**
	 * @return list<Check>
	 */
	private function project(?Project $project): array
	{
		if (!$project instanceof Project) {
			return [];
		}

		return [
			$this->delivery($project),
			...$this->sender($project),
			...$this->routes($project),
		];
	}

	private function delivery(Project $project): Check
	{
		return $project->deliveryMode === Project::DELIVERY_MODE_SANDBOX
			? Check::warning(CheckType::Delivery, 'The project is in sandbox mode, so emails are not delivered to their recipients.')
			: Check::ok(CheckType::Delivery, 'The project is live.');
	}

	/**
	 * Lettermint only sends from domains of the project. Sites may override the system email address.
	 *
	 * @return list<Check>
	 */
	private function sender(Project $project): array
	{
		$checks = [];
		$domain = $this->domain($this->settings->senderEmail);

		if ($domain !== null) {
			$checks[] = $this->senderDomain($project, $domain);
		}

		foreach ($this->sites as $site) {
			if ($site->uid === null || null === $domain = $this->domain($this->settings->site($site->uid)->senderEmail)) {
				continue;
			}

			$checks[] = $this->senderDomain($project, $domain, $site);
		}

		return $checks;
	}

	/**
	 * Lowercased, or null without one.
	 */
	private function domain(?string $email): ?string
	{
		if ($email === null || false === $at = strrpos($email, '@')) {
			return null;
		}

		return strtolower(substr($email, $at + 1));
	}

	private function senderDomain(Project $project, string $domain, ?Site $site = null): Check
	{
		$match = $this->find($project->domains, static fn(Domain $item): bool => $item->domain !== null && strtolower($item->domain) === $domain);

		// Without a status, the verification is unknown.
		$status = match (true) {
			$match === null => CheckStatus::Error,
			$match->status !== null && $match->status !== Domain::STATUS_VERIFIED => CheckStatus::Warning,
			default => CheckStatus::Ok,
		};

		if (!$site instanceof Site) {
			return new Check(CheckType::Sender, $status, match ($status) {
				CheckStatus::Error => 'The domain “{domain}” of the system email address is not a domain of the project.',
				CheckStatus::Warning => 'The domain “{domain}” of the system email address is not fully verified.',
				CheckStatus::Ok => 'The domain “{domain}” of the system email address belongs to the project.',
			}, ['domain' => $domain]);
		}

		return new Check(CheckType::Sender, $status, match ($status) {
			CheckStatus::Error => 'The domain “{domain}” of the system email address of {site} is not a domain of the project.',
			CheckStatus::Warning => 'The domain “{domain}” of the system email address of {site} is not fully verified.',
			CheckStatus::Ok => 'The domain “{domain}” of the system email address of {site} belongs to the project.',
		}, ['domain' => $domain, 'site' => $site->getName()], $site->handle ?? $site->uid);
	}

	/**
	 * Route slugs must be outgoing routes of the project.
	 *
	 * @return list<Check>
	 */
	private function routes(Project $project): array
	{
		$routes = array_filter($project->routes, static fn(Route $route): bool => $route->outgoing());
		// Strict comparison, so null slugs match nothing.
		$slugs = array_column($routes, 'slug');
		// The last default route wins.
		$default = $this->find(array_reverse($routes), static fn(Route $route): bool => $route->default);
		return [...$this->adapterRoute($slugs, $default), ...$this->siteRoutes($slugs)];
	}

	/**
	 * Without an adapter route, names the project's default route, if any.
	 *
	 * @param array<?string> $slugs Outgoing route slugs.
	 * @return list<Check>
	 */
	private function adapterRoute(array $slugs, ?Route $default): array
	{
		$route = $this->settings->route;

		if ($route === null) {
			return $default instanceof Route
				? [Check::ok(CheckType::Route, 'Emails use the default route “{route}” of the project.', ['route' => $default->slug ?? ''])]
				: [];
		}

		return [in_array($route, $slugs, true)
			? Check::ok(CheckType::Route, 'The route “{route}” belongs to the project.', ['route' => $route])
			: Check::error(CheckType::Route, 'The route “{route}” is not an outgoing route of the project.', ['route' => $route])];
	}

	/**
	 * @param array<?string> $slugs Outgoing route slugs.
	 * @return list<Check>
	 */
	private function siteRoutes(array $slugs): array
	{
		$checks = [];

		foreach ($this->sites as $site) {
			if ($site->uid === null || null === $route = $this->settings->site($site->uid)->route) {
				continue;
			}

			$subject = $site->handle ?? $site->uid;
			$params = ['route' => $route, 'site' => $site->getName()];
			$checks[] = in_array($route, $slugs, true)
				? Check::ok(CheckType::Route, 'The route “{route}” of {site} belongs to the project.', $params, $subject)
				: Check::error(CheckType::Route, 'The route “{route}” of {site} is not an outgoing route of the project.', $params, $subject);
		}

		return $checks;
	}

	/**
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
