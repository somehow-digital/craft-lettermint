<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use SensitiveParameter;
use yii\base\Component;

/**
 * Loads the team and usage of the team API token, and the statistics and domains of the project of the token, for the
 * utility and the widget (Pro edition), and creates the clients of the team API, for the health checks as well.
 *
 * The plugin registers it as its `team` component, which projects can configure or replace through the plugin configs
 * of Craft, e.g. to read the team API differently.
 */
class TeamService extends Component
{
	/**
	 * The seconds the data is cached for, so the utility and the widget do not load it from Lettermint each time.
	 */
	public const CACHE_DURATION = 300;

	/**
	 * The data of the page for this request, as its content, toolbar and footer are rendered separately.
	 */
	private ?UtilityData $data = null;

	/**
	 * A client of the team API, authenticated with the team token.
	 */
	public function api(#[SensitiveParameter] string $token): TeamApi
	{
		return new TeamApi($token);
	}

	/**
	 * The data of the page and the widget, from the cache when it was loaded within the last minutes.
	 *
	 * It is kept for the request as well, as the content, toolbar and footer are rendered separately.
	 */
	public function data(): UtilityData
	{
		if ($this->data instanceof UtilityData) {
			return $this->data;
		}

		// The settings are resolved once, for both the key of the cache and the data.
		$settings = MailSettings::settings();

		// Without Lettermint as the transport, nothing is cached.
		if (!$settings instanceof Settings) {
			return $this->data = $this->fetch(null);
		}

		return $this->data = Craft::$app->getCache()?->getOrSet($this->cacheKey($settings), fn(): UtilityData => $this->fetch($settings), self::CACHE_DURATION)
			?? $this->fetch($settings);
	}

	/**
	 * Clears the cached data, so it is loaded from Lettermint again.
	 */
	public function refresh(): void
	{
		$settings = MailSettings::settings();

		// Without Lettermint as the transport, nothing is cached.
		if ($settings instanceof Settings) {
			Craft::$app->getCache()?->delete($this->cacheKey($settings));
		}

		$this->data = null;
	}

	/**
	 * The key of the data cached for settings.
	 */
	private function cacheKey(Settings $settings): string
	{
		return MailSettings::cacheKey($settings, 'utility');
	}

	/**
	 * Loads the data of the page from Lettermint, with the settings of the email settings, or null when Lettermint is
	 * not the transport.
	 */
	private function fetch(?Settings $settings): UtilityData
	{
		$token = $settings?->teamToken;
		$projectId = $settings?->projectId;
		$sections = $token !== null ? $this->api($token)->overview($projectId) : null;
		$checks = $settings instanceof Settings ? Plugin::instance()->getHealth()->checks($settings, $sections) : null;

		return UtilityData::from($settings, $sections, $checks);
	}
}
