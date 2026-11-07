<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use Craft;
use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\api\TeamApi;
use SomehowDigital\Craft\Lettermint\api\TokenChecker;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\helpers\Checks;
use SomehowDigital\Craft\Lettermint\models\CheckResult;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\TeamApiData;
use yii\base\Component;

/**
 * Builds the checks of the health endpoint, the utility and the widget (Pro edition) from Lettermint and Craft, so both
 * describe the same facts the same way.
 *
 * The health checks and the utility read the team API with `teamApi()` and check the settings against it with
 * `checks()`, which checks the project token with `tokenStatus()`.
 *
 * The plugin registers it as its `checks` component.
 *
 * @internal
 */
class ChecksService extends Component
{
	/**
	 * The status of a project token, checked with Lettermint, or null when there is no token to check.
	 */
	public function tokenStatus(#[SensitiveParameter] ?string $token): ?TokenStatus
	{
		return TokenChecker::check($token);
	}

	/**
	 * The results of the team API, loaded with the team token of the settings, or null without a team token.
	 *
	 * @param bool $withStats Whether the statistics of the project are loaded as well, which the health checks leave out.
	 */
	public function teamApi(Settings $settings, bool $withStats = false): ?TeamApiData
	{
		$token = $settings->teamToken;

		return $token !== null ? (new TeamApi($token))->load($settings->projectId, $withStats) : null;
	}

	/**
	 * The checks of the settings, against the results of the team API and all sites, also the disabled ones, as they may
	 * still send emails.
	 *
	 * @param TeamApiData|null $api The results of the team API, or null without a team token.
	 */
	public function checks(Settings $settings, ?TeamApiData $api): CheckResult
	{
		return Checks::build(
			$settings,
			$this->tokenStatus($settings->token),
			$api,
			Craft::$app->getSites()->getAllSites(true),
		);
	}
}
