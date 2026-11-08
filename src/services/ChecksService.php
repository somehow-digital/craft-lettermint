<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use Craft;
use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\base\LettermintApiInterface;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\models\CheckResult;
use SomehowDigital\Craft\Lettermint\models\Checks;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\TeamApiData;
use yii\base\Component;

/**
 * Builds the checks of the health endpoint, the utility and the widget (Pro edition) from Lettermint and Craft, so both
 * describe the same facts the same way.
 *
 * The health checks and the utility check the project token with `tokenStatus()`, read the team API with `teamApi()`
 * and check the settings against both with `checks()`.
 *
 * The plugin registers it as its `checks` component, and binds the API of Lettermint that it reads in the container.
 *
 * @internal
 */
final class ChecksService extends Component
{
	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly LettermintApiInterface $api,
		array $config = [],
	) {
		// Yii configures the component, which takes only its event handlers and behaviors from its config here.
		parent::__construct($config); // @pest-mutate-ignore: RemoveMethodCall
	}

	/**
	 * The status of a project token, checked with Lettermint, or null when there is no token to check.
	 */
	public function tokenStatus(#[SensitiveParameter] ?string $token): ?TokenStatus
	{
		return $this->api->tokenStatus($token);
	}

	/**
	 * The results of the team API, loaded with the team token of the settings, or null without a team token.
	 *
	 * @param bool $withStats Whether the statistics of the project are loaded as well, which the health checks leave out.
	 */
	public function teamApi(Settings $settings, bool $withStats = false): ?TeamApiData
	{
		$token = $settings->teamToken;

		return $token !== null ? $this->api->teamApi($token, $settings->projectId, $withStats) : null;
	}

	/**
	 * The checks of the settings, against the status of the project token, the results of the team API and all sites,
	 * also the disabled ones, as they may still send emails.
	 *
	 * @param TokenStatus|null $tokenStatus The status of the project token, or null when it is not set.
	 * @param TeamApiData|null $api The results of the team API, or null without a team token.
	 */
	public function checks(Settings $settings, ?TokenStatus $tokenStatus, ?TeamApiData $api): CheckResult
	{
		return Checks::build($settings, $tokenStatus, $api, Craft::$app->getSites()->getAllSites(true));
	}
}
