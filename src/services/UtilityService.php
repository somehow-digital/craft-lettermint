<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use DateTimeImmutable;
use SomehowDigital\Craft\Lettermint\api\TeamApi;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\helpers\MailSettings;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\UtilityData;
use SomehowDigital\Craft\Lettermint\Plugin;
use yii\base\Component;

/**
 * Loads the team and usage of the team API token, and the statistics and domains of the project of the token, for the
 * utility and the widget (Pro edition), through the team API and the checks of the `checks` component.
 *
 * The data is fresh for a few minutes. Older data is kept for longer, so the utility and the widget show it right away
 * while they load it again.
 *
 * The plugin registers it as its `utility` component.
 *
 * @internal
 */
class UtilityService extends Component
{
	/**
	 * The seconds the data is fresh for, so the utility and the widget do not load it from Lettermint each time.
	 */
	public const CACHE_DURATION = 300;

	/**
	 * The seconds the data is kept for, to be shown while it is loaded again.
	 */
	public const STALE_DURATION = 604800;

	/**
	 * The data of the page for this request, as its content and footer are rendered separately.
	 */
	private ?UtilityData $data = null;

	/**
	 * The data that can be shown without loading it from Lettermint, fresh or not, or null when there is none.
	 *
	 * Without Lettermint as the transport, there is nothing to load, so the data is always there.
	 */
	public function cached(): ?UtilityData
	{
		if ($this->data instanceof UtilityData) {
			return $this->data;
		}

		$settings = MailSettings::settings();

		if (!$settings instanceof Settings) {
			return $this->data = UtilityData::from(null, null, null);
		}

		return $this->data = MailSettings::peek($settings, 'utility', UtilityData::class);
	}

	/**
	 * Whether the data was loaded within the last minutes, so it is not loaded again.
	 */
	public function isFresh(UtilityData $data, DateTimeImmutable $now = new DateTimeImmutable()): bool
	{
		return $data->loadedAt > $now->modify(sprintf('-%d seconds', self::CACHE_DURATION));
	}

	/**
	 * The fresh data of the page and the widget, loaded from Lettermint unless it is.
	 */
	public function data(): UtilityData
	{
		$data = $this->cached();

		return $data instanceof UtilityData && $this->isFresh($data) ? $data : $this->reload();
	}

	/**
	 * Loads the data from Lettermint again, and keeps it, unless Lettermint could not be reached for any of it, so it is
	 * loaded again with the next request instead of keeping the failure.
	 */
	public function reload(): UtilityData
	{
		$settings = MailSettings::settings();

		// Without Lettermint as the transport, there is nothing to load or keep.
		if (!$settings instanceof Settings) {
			return $this->data = UtilityData::from(null, null, null);
		}

		$checks = Plugin::instance()->getChecks();
		$api = $checks->teamApi($settings, withStats: true);
		$tokenStatus = $checks->tokenStatus($settings->token);
		$data = UtilityData::from($settings, $api, $checks->checks($settings, $tokenStatus, $api));

		if ($tokenStatus !== TokenStatus::Unknown && $api?->unavailable() !== true) {
			MailSettings::store($settings, 'utility', $data, self::STALE_DURATION);
		}

		return $this->data = $data;
	}

	/**
	 * Data in the layout that the data of the settings is likely shown in, for the utility and the widget to show while
	 * nothing is cached.
	 */
	public function placeholder(): UtilityData
	{
		return UtilityData::placeholder(MailSettings::settings(), TeamApi::STATS_DAYS);
	}
}
