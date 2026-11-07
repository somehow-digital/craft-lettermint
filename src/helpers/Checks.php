<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\helpers;

use craft\models\Site;
use SomehowDigital\Craft\Lettermint\enums\ApiError;
use SomehowDigital\Craft\Lettermint\enums\CheckType;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\models\Check;
use SomehowDigital\Craft\Lettermint\models\CheckResult;
use SomehowDigital\Craft\Lettermint\models\Domain;
use SomehowDigital\Craft\Lettermint\models\Project;
use SomehowDigital\Craft\Lettermint\models\Route;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\TeamApiData;
use SomehowDigital\Craft\Lettermint\models\UsageSummary;

/**
 * Builds the checks of the tokens, the usage and the project, for the health endpoint, the utility and the widget, so
 * they describe the same facts the same way.
 *
 * The project API token is always checked. With the results of the team API, the team token, the usage of the plan
 * and the settings against the project are checked as well.
 *
 * Everything that is checked is passed in, so the checks read nothing from Craft or Lettermint themselves.
 * `ChecksService::teamApi()` loads the results for them, and `ChecksService::checks()` builds them from the resolved
 * settings of the adapter and the sites of Craft.
 *
 * @internal
 */
final readonly class Checks
{
	/**
	 * The share of the monthly volume of the plan from which the usage is a warning.
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
	 * The checks, which stop after the team token once Lettermint rejects it, as nothing more can be loaded then.
	 *
	 * @param TokenStatus|null $tokenStatus The status of the project token, or null when it is not set.
	 * @param TeamApiData|null $api The results of the team API, or null without a team token.
	 * @param array<Site> $sites The sites whose routes are checked, including the disabled ones.
	 */
	public static function build(Settings $settings, ?TokenStatus $tokenStatus, ?TeamApiData $api, array $sites): CheckResult
	{
		return (new self($settings, $tokenStatus, $sites))->result($api);
	}

	private function result(?TeamApiData $api): CheckResult
	{
		$token = $this->token();

		// Without a team token, there is nothing more to check.
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

	/**
	 * Without an accepted project token, no emails are sent.
	 */
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
	 * The team token is not needed for sending, so its problems are warnings.
	 */
	private function teamToken(TeamApiData $api): Check
	{
		return match ($api->teamTokenStatus()) {
			TokenStatus::Valid => Check::ok(CheckType::TeamToken, 'Lettermint accepts the team API token.'),
			TokenStatus::Invalid => Check::warning(CheckType::TeamToken, 'Lettermint rejects the team API token.'),
			TokenStatus::Unknown => Check::warning(CheckType::TeamToken, 'The team could not be loaded from Lettermint.'),
		};
	}

	/**
	 * The usage of the current period against the monthly volume of the plan, or null when it is not known.
	 */
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
	 * A project that could not be loaded is a warning, as emails are still sent.
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
	 * The checks of the settings against the project, without any when there is no project.
	 *
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
	 * Checks the domain of the system email address, which Lettermint only sends from when it is a domain of the project.
	 *
	 * @return list<Check>
	 */
	private function sender(Project $project): array
	{
		$email = $this->settings->senderEmail;

		if ($email === null || false === $at = strrpos($email, '@')) {
			return [];
		}

		return [$this->senderDomain($project, strtolower(substr($email, $at + 1)))];
	}

	private function senderDomain(Project $project, string $domain): Check
	{
		$params = ['domain' => $domain];
		$match = $this->find($project->domains, static fn(Domain $item): bool => $item->domain !== null && strtolower($item->domain) === $domain);

		if ($match === null) {
			return Check::error(CheckType::Sender, 'The domain “{domain}” of the system email address is not a domain of the project.', $params);
		}

		// A domain without a status was not listed, so nothing is known about its verification.
		return $match->status !== null && $match->status !== Domain::STATUS_VERIFIED
			? Check::warning(CheckType::Sender, 'The domain “{domain}” of the system email address is not fully verified.', $params)
			: Check::ok(CheckType::Sender, 'The domain “{domain}” of the system email address belongs to the project.', $params);
	}

	/**
	 * Checks the route slugs of the adapter and the sites, which must be outgoing routes of the project.
	 *
	 * @return list<Check>
	 */
	private function routes(Project $project): array
	{
		$routes = array_filter($project->routes, static fn(Route $route): bool => $route->outgoing());
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
	 * Checks the routes of the sites that set their own.
	 *
	 * @param array<?string> $slugs The slugs of the outgoing routes of the project.
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
