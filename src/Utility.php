<?php

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\base\Utility as UtilityBase;
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
		return Adapter::fromMailSettings();
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
		$invalid = Overview::invalid($sections);

		return [
			'configured' => $adapter !== null,
			'hasToken' => $token !== null,
			'invalid' => $invalid,
			'tokens' => $adapter !== null ? [
				'token' => $adapter->checkToken()?->value,
				'teamToken' => Overview::teamTokenStatus($sections)?->value,
			] : null,
			'sections' => $sections,
			'usage' => Overview::usage($sections),
			'project' => $sections !== null ? Overview::project($adapter, $projectId, $sections) : null,
			'statsDays' => TeamApi::STATS_DAYS,
			'pageSize' => TeamApi::PAGE_SIZE,
			'dashboardUrl' => self::DASHBOARD_URL,
			'loadedAt' => new DateTimeImmutable(),
		];
	}
}
