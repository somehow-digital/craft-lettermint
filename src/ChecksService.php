<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\helpers\App;
use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\data\Sections;
use yii\base\Component;

/**
 * Builds the checks of the health endpoint, the utility and the widget (Pro edition) from Lettermint and Craft, so both
 * describe the same facts the same way.
 *
 * It reads the team API and checks the project token for them, so projects can change how either is done by replacing
 * `api()` or `tokenStatus()`, which the health checks and the utility then both use.
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
	 * The checks of the settings, against the sections of the team API, the system email address and all sites, also
	 * the disabled ones, as they may still send emails.
	 *
	 * @param Sections|null $sections The sections of the team API, or null without a team token.
	 */
	public function checks(Settings $settings, ?Sections $sections): Checks
	{
		return new Checks(
			$settings,
			$this->tokenStatus($settings->token),
			$sections,
			Env::value(App::mailSettings()->fromEmail),
			Craft::$app->getSites()->getAllSites(true),
		);
	}
}
