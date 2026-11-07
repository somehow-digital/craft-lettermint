<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use Craft;
use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\helpers\Checks;
use SomehowDigital\Craft\Lettermint\helpers\TeamApi;
use SomehowDigital\Craft\Lettermint\helpers\TokenChecker;
use SomehowDigital\Craft\Lettermint\models\Sections;
use SomehowDigital\Craft\Lettermint\models\Settings;
use yii\base\Component;

/**
 * Builds the checks of the health endpoint, the utility and the widget (Pro edition) from Lettermint and Craft, so both
 * describe the same facts the same way.
 *
 * It reads the team API and checks the project token for them in `load()`, so projects can change how either is done by
 * replacing `load()`, `api()` or `tokenStatus()`, which the health checks and the utility then both use.
 *
 * The plugin registers it as its `checks` component, which projects can configure or replace through the plugin configs
 * of Craft.
 */
class ChecksService extends Component
{
	/**
	 * A client of the team API, authenticated with the team token.
	 */
	public function api(#[SensitiveParameter] string $token): TeamApi
	{
		return new TeamApi($token);
	}

	/**
	 * The status of a project token, checked with Lettermint, or null when there is no token to check.
	 */
	public function tokenStatus(#[SensitiveParameter] ?string $token): ?TokenStatus
	{
		return TokenChecker::check($token);
	}

	/**
	 * Loads the sections of the team API with the team token of the settings, and checks the settings against them.
	 *
	 * Without a team token, nothing is loaded from the team API, and only the project token is checked.
	 *
	 * @param bool $withStats Whether the statistics of the project are loaded as well, which the health checks leave out.
	 */
	public function load(Settings $settings, bool $withStats = false): Checks
	{
		$token = $settings->teamToken;
		$sections = null;

		if ($token !== null) {
			$api = $this->api($token);
			$sections = $withStats ? $api->overview($settings->projectId) : $api->health($settings->projectId);
		}

		return $this->checks($settings, $sections);
	}

	/**
	 * The checks of the settings, against the sections of the team API and all sites, also the disabled ones, as they may
	 * still send emails.
	 *
	 * @param Sections|null $sections The sections of the team API, or null without a team token.
	 */
	public function checks(Settings $settings, ?Sections $sections): Checks
	{
		return new Checks(
			$settings,
			$this->tokenStatus($settings->token),
			$sections,
			Craft::$app->getSites()->getAllSites(true),
		);
	}
}
