<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use Craft;
use craft\base\UtilityInterface;
use craft\web\View;
use DateTimeImmutable;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\models\Stats;
use SomehowDigital\Craft\Lettermint\models\UtilityData;
use yii\base\Component;

/**
 * Loads the team and usage of the team API token, and the statistics and domains of the project of the token, for the
 * utility and the widget (Pro edition), through the team API and the checks of the checks service, and renders them.
 *
 * The data is fresh for a few minutes. Older data is kept for longer, so the utility and the widget show it right away
 * while they load it again.
 *
 * The plugin registers it as its `utility` component, and configures it with its utility through the container, so it
 * does not depend on it.
 *
 * @internal
 */
final class UtilityService extends Component
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
	 * The URL of the dashboard of Lettermint.
	 */
	public const DASHBOARD_URL = 'https://app.lettermint.co/';

	/**
	 * The class of the utility of the plugin, which the data is shown in.
	 *
	 * @var class-string<UtilityInterface>|null
	 */
	public ?string $utility = null;

	/**
	 * The data of the page for this request, as its content and footer are rendered separately.
	 */
	private ?UtilityData $data = null;

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly ChecksService $checks,
		private readonly MailSettingsService $mailSettings,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * Whether the current user may see the data of the utility, on its page, in the widget or by refreshing it, which
	 * requires the utility to be selectable, i.e. the pro edition.
	 */
	public function isAccessible(): bool
	{
		return $this->utility !== null && $this->utility::isSelectable() && Craft::$app->getUtilities()->checkAuthorization($this->utility);
	}

	/**
	 * Renders a template of the utility or the widget with the data, and what the templates link to.
	 *
	 * @param bool $loading Whether the data is loaded again.
	 * @param bool $placeholder Whether the data is placeholder data, which is shown as a skeleton.
	 */
	public function render(string $template, ?UtilityData $data = null, bool $loading = false, bool $placeholder = false): string
	{
		$view = Craft::$app->getView();
		$view->registerTranslations('lettermint', ['This could not be loaded from Lettermint.']);

		return $view->renderTemplate($template, [
			'data' => $data,
			'loading' => $loading,
			'placeholder' => $placeholder,
			'statsDays' => Stats::DAYS,
			'dashboardUrl' => self::DASHBOARD_URL,
			'utilityPath' => $this->utility !== null ? 'utilities/' . $this->utility::id() : null,
		], View::TEMPLATE_MODE_CP);
	}

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

		$settings = $this->mailSettings->settings();

		if (!$settings instanceof Settings) {
			return $this->reload();
		}

		return $this->data = $this->mailSettings->peek($settings, 'utility', UtilityData::class);
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
		$settings = $this->mailSettings->settings();

		// Without Lettermint as the transport, there is nothing to load or keep.
		if (!$settings instanceof Settings) {
			return $this->data = UtilityData::from(null, null, null);
		}

		$api = $this->checks->teamApi($settings, withStats: true);
		$tokenStatus = $this->checks->tokenStatus($settings->token);
		$data = UtilityData::from($settings, $api, $this->checks->checks($settings, $tokenStatus, $api));

		if ($tokenStatus !== TokenStatus::Unknown && $api?->unavailable() !== true) {
			$this->mailSettings->store($settings, 'utility', $data, self::STALE_DURATION);
		}

		return $this->data = $data;
	}

	/**
	 * Data in the layout that the data of the settings is likely shown in, for the utility and the widget to show while
	 * nothing is cached.
	 */
	public function placeholder(): UtilityData
	{
		return UtilityData::placeholder($this->mailSettings->settings());
	}
}
