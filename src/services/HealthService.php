<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\services;

use DateTimeImmutable;
use SomehowDigital\Craft\Lettermint\models\HealthReport;
use SomehowDigital\Craft\Lettermint\models\Settings;
use yii\base\Component;

/**
 * Checks whether emails can be sent with Lettermint, for monitoring through the health endpoint (Pro edition).
 *
 * The checks are built by the checks service, which the utility uses as well.
 *
 * The report is cached for a minute, so frequent monitoring does not run into the rate limits of Lettermint.
 *
 * The plugin registers it as its `health` component.
 *
 * @internal
 */
final class HealthService extends Component
{
	/**
	 * The seconds a report is cached for.
	 */
	public const CACHE_DURATION = 60;

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly ChecksService $checks,
		private readonly MailSettingsService $mailSettings,
		array $config = [],
	) {
		// Yii configures the component, which takes only its event handlers and behaviors from its config here.
		parent::__construct($config); // @pest-mutate-ignore: RemoveMethodCall
	}

	/**
	 * The report of the settings, from the cache when they were checked within the last minute.
	 */
	public function report(Settings $settings): HealthReport
	{
		// Other settings, or another system email address, are checked again right away.
		return $this->mailSettings->cached($settings, 'health', self::CACHE_DURATION, fn(): HealthReport => $this->check($settings));
	}

	/**
	 * Checks the settings with Lettermint.
	 */
	public function check(Settings $settings): HealthReport
	{
		$api = $this->checks->teamApi($settings);

		return new HealthReport(
			checks: $this->checks->checks($settings, $this->checks->tokenStatus($settings->token), $api)->all(),
			usage: $api?->usageSummary(),
			date: new DateTimeImmutable(),
		);
	}
}
