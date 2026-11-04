<?php

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\base\Utility as UtilityBase;
use craft\helpers\App;
use craft\helpers\MailerHelper;
use DateTimeImmutable;
use SomehowDigital\Craft\Lettermint\web\assets\utility\UtilityAsset;
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
		return Plugin::isPro();
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
		$view = Craft::$app->getView();
		$view->registerAssetBundle(UtilityAsset::class);

		return $view->renderTemplate("lettermint/utility/{$part}", self::data());
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
		$invalid = $sections !== null && in_array(TeamApi::ERROR_INVALID, array_column(array_filter($sections), 'error'), true);

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

		return in_array($sections['team']['error'], [null, TeamApi::ERROR_FORBIDDEN], true) ? TokenStatus::Valid : TokenStatus::Unknown;
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
			'checks' => is_array($data)
				? (new ProjectChecks($adapter, $data, $sections['domains']['data']['data'] ?? []))->all()
				: [],
		];
	}
}
