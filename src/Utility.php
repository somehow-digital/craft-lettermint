<?php

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\base\Utility as UtilityBase;
use craft\helpers\App;
use craft\helpers\MailerHelper;
use DateTimeImmutable;
use WeakMap;

/**
 * Shows the team, usage, statistics, projects and domains of the Lettermint team of the team API token (Pro edition).
 */
class Utility extends UtilityBase
{
	/**
	 * The URL of the dashboard of Lettermint.
	 */
	public const DASHBOARD_URL = 'https://dash.lettermint.co/';

	/**
	 * The data of the page for each app, as its content, toolbar and footer are rendered separately.
	 *
	 * @var WeakMap<object,array<string,mixed>>|null
	 */
	private static ?WeakMap $data = null;

	public static function displayName(): string
	{
		return Craft::t('lettermint', 'Lettermint');
	}

	public static function id(): string
	{
		return 'lettermint';
	}

	public static function icon(): ?string
	{
		return __DIR__ . '/icon-mask.svg';
	}

	public static function isSelectable(): bool
	{
		return Plugin::getInstance()?->is(Plugin::EDITION_PRO) ?? false;
	}

	public static function contentHtml(): string
	{
		return self::render('content');
	}

	public static function toolbarHtml(): string
	{
		return self::render('toolbar');
	}

	public static function footerHtml(): string
	{
		return self::render('footer');
	}

	/**
	 * The adapter of the email settings, or null when Lettermint is not the transport.
	 */
	public static function adapter(): ?Adapter
	{
		$settings = App::mailSettings();

		if ($settings->transportType !== Adapter::class) {
			return null;
		}

		/** @var Adapter */
		return MailerHelper::createTransportAdapter(Adapter::class, $settings->transportSettings);
	}

	private static function render(string $part): string
	{
		return Craft::$app->getView()->renderTemplate("lettermint/utility/{$part}", self::data());
	}

	/**
	 * The data of the page, loaded from Lettermint once for each app.
	 *
	 * @return array<string,mixed>
	 */
	private static function data(): array
	{
		self::$data ??= new WeakMap();

		return self::$data[Craft::$app] ??= self::fetch();
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function fetch(): array
	{
		$adapter = self::adapter();
		$token = $adapter?->getTeamToken();
		$projectId = $adapter?->getProjectId();
		$sections = $token !== null ? (new TeamApi($token))->overview($projectId) : null;
		$invalid = $sections !== null && in_array('invalid', array_column(array_filter($sections), 'error'), true);

		return [
			'configured' => $adapter !== null,
			'hasToken' => $token !== null,
			'invalid' => $invalid,
			'tokens' => $adapter !== null ? [
				'token' => $adapter->checkToken()?->value,
				'teamToken' => self::teamTokenStatus($sections, $invalid)?->value,
			] : null,
			'sections' => $sections,
			'usage' => self::usage($sections),
			'project' => $sections !== null ? self::project($adapter, $projectId, $sections) : null,
			'statsDays' => TeamApi::STATS_DAYS,
			'pageSize' => TeamApi::PAGE_SIZE,
			'dashboardUrl' => self::DASHBOARD_URL,
			'loadedAt' => new DateTimeImmutable(),
		];
	}

	/**
	 * The status of the team token, taken from the requests of the page instead of a ping of its own.
	 *
	 * A token that Lettermint accepts is valid, even when it is not allowed to read the team.
	 */
	private static function teamTokenStatus(?array $sections, bool $invalid): ?TokenStatus
	{
		if ($sections === null) {
			return null;
		}

		if ($invalid) {
			return TokenStatus::Invalid;
		}

		return in_array($sections['team']['error'], [null, 'forbidden'], true) ? TokenStatus::Valid : TokenStatus::Unknown;
	}

	/**
	 * The usage of the current period, compared with the monthly volume included in the plan.
	 *
	 * The usage of the current period of Lettermint matches its dashboard, though the dates of the period can be ones
	 * that have passed, so they are only given when they contain the current time.
	 *
	 * @return array{used:int,included:?int,share:?float,start:?string,end:?string}|null
	 */
	private static function usage(?array $sections): ?array
	{
		$period = $sections['usage']['data']['current_period'] ?? null;

		if (!is_array($period)) {
			return null;
		}

		$used = (int) ($period['usage'] ?? 0);
		$included = $sections['team']['data']['included_volume'] ?? null;
		$included = is_numeric($included) && $included > 0 ? (int) $included : null;

		$now = new DateTimeImmutable();
		$start = self::date($period['period_start'] ?? null);
		$end = self::date($period['period_end'] ?? null);
		$dated = $start !== null && $end !== null && $start <= $now && $now < $end;

		return [
			'used' => $used,
			'included' => $included,
			'share' => $included !== null ? $used / $included : null,
			'start' => $dated ? $period['period_start'] : null,
			'end' => $dated ? $period['period_end'] : null,
		];
	}

	private static function date(mixed $value): ?DateTimeImmutable
	{
		if (!is_string($value) || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}
	}

	/**
	 * The project of the token, with checks of the settings against it.
	 *
	 * @return array{id:?string,auto:bool,data:?array<mixed>,error:?string,checks:list<array{status:string,text:string}>}
	 */
	private static function project(Adapter $adapter, ?string $projectId, array $sections): array
	{
		$section = $sections['project'] ?? null;
		$data = $section['data'] ?? null;

		// The project details only have their statistics when they are included, which the list of projects always has.
		if (is_array($data)) {
			foreach ($sections['projects']['data']['data'] ?? [] as $listed) {
				if (($listed['id'] ?? null) === ($data['id'] ?? false) && is_array($listed['last_28_days'] ?? null)) {
					$data['last_28_days'] = $listed['last_28_days'];
					break;
				}
			}
		}

		return [
			'id' => $projectId,
			'auto' => $projectId === null && $section !== null,
			'data' => $data,
			'error' => $section['error'] ?? null,
			'checks' => is_array($data) ? [
				...self::deliveryCheck($data),
				...self::senderCheck($data, $sections['domains']['data']['data'] ?? []),
				...self::routeChecks($adapter, $data),
			] : [],
		];
	}

	/**
	 * @return list<array{status:string,text:string}>
	 */
	private static function deliveryCheck(array $project): array
	{
		if (($project['delivery_mode'] ?? null) === 'sandbox') {
			return [self::check('warning', 'The project is in sandbox mode, so emails are not delivered to their recipients.')];
		}

		return [self::check('ok', 'The project is live.')];
	}

	/**
	 * Checks the domain of the system email address, which Lettermint only sends from when it is a domain of the project.
	 *
	 * @param array<mixed> $domains The domains of the team, with their status.
	 * @return list<array{status:string,text:string}>
	 */
	private static function senderCheck(array $project, array $domains): array
	{
		$email = App::parseEnv(App::mailSettings()->fromEmail);

		if (!is_string($email) || !str_contains($email, '@')) {
			return [];
		}

		$domain = strtolower(substr($email, strrpos($email, '@') + 1));
		$match = null;

		foreach ($project['domains'] ?? [] as $projectDomain) {
			if (strtolower((string) ($projectDomain['domain'] ?? '')) === $domain) {
				$match = $projectDomain;
				break;
			}
		}

		if ($match === null) {
			return [self::check('error', 'The domain “{domain}” of the system email address is not a domain of the project.', ['domain' => $domain])];
		}

		// The domains of a project leave out their status, which the domains of the team have.
		$status = null;

		foreach ($domains as $teamDomain) {
			if (($teamDomain['id'] ?? null) === ($match['id'] ?? false)) {
				$status = $teamDomain['status'] ?? null;
				break;
			}
		}

		if ($status !== null && $status !== 'verified') {
			return [self::check('warning', 'The domain “{domain}” of the system email address is not fully verified.', ['domain' => $domain])];
		}

		return [self::check('ok', 'The domain “{domain}” of the system email address belongs to the project.', ['domain' => $domain])];
	}

	/**
	 * Checks the route slugs of the adapter and the sites, which must be outgoing routes of the project.
	 *
	 * @return list<array{status:string,text:string}>
	 */
	private static function routeChecks(Adapter $adapter, array $project): array
	{
		if (!isset($project['routes']) || !is_array($project['routes'])) {
			return [];
		}

		$routes = [];
		$default = null;

		foreach ($project['routes'] as $route) {
			if (($route['route_type'] ?? null) === 'inbound') {
				continue;
			}

			$routes[$route['slug'] ?? ''] = $route;

			if ($route['is_default'] ?? false) {
				$default = $route;
			}
		}

		$checks = [];
		$route = App::parseEnv($adapter->route);

		if (is_string($route) && trim($route) !== '') {
			$checks[] = isset($routes[trim($route)])
				? self::check('ok', 'The route “{route}” belongs to the project.', ['route' => trim($route)])
				: self::check('error', 'The route “{route}” is not an outgoing route of the project.', ['route' => trim($route)]);
		} elseif ($default !== null) {
			$checks[] = self::check('ok', 'Emails use the default route “{route}” of the project.', ['route' => $default['slug'] ?? '']);
		}

		foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
			if (null === $siteRoute = $adapter->getSiteRoute($site->uid)) {
				continue;
			}

			$checks[] = isset($routes[$siteRoute])
				? self::check('ok', 'The route “{route}” of {site} belongs to the project.', ['route' => $siteRoute, 'site' => $site->getName()])
				: self::check('error', 'The route “{route}” of {site} is not an outgoing route of the project.', ['route' => $siteRoute, 'site' => $site->getName()]);
		}

		return $checks;
	}

	/**
	 * @return array{status:string,text:string}
	 */
	private static function check(string $status, string $message, array $params = []): array
	{
		return ['status' => $status, 'text' => Craft::t('lettermint', $message, $params)];
	}
}
