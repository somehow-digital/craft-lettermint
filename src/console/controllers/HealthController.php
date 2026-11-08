<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use SomehowDigital\Craft\Lettermint\enums\CheckStatus;
use SomehowDigital\Craft\Lettermint\models\Check;
use SomehowDigital\Craft\Lettermint\models\HealthReport;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\Plugin;
use yii\console\ExitCode;

/**
 * Runs the health checks, e.g. during a deployment (Pro edition).
 *
 * Same checks as the health endpoint, but uncached and without the health token.
 *
 * @internal
 */
final class HealthController extends Controller
{
	/**
	 * @var bool Whether to output the report as JSON, like the health endpoint.
	 */
	public bool $json = false;

	/**
	 * @var bool Whether warnings fail the command.
	 */
	public bool $strict = false;

	public function options($actionID): array
	{
		return [...parent::options($actionID), 'json', 'strict'];
	}

	/**
	 * Checks whether emails can be sent with Lettermint.
	 *
	 * Exits with 0 for `ok` and `warning`, 69 for `error` (and `warning` with `--strict`).
	 */
	public function actionIndex(): int
	{
		if (!Plugin::isPro()) {
			$this->stderr("The health check requires the Pro edition.\n", Console::FG_RED);

			return ExitCode::CONFIG;
		}

		$status = Plugin::instance()->getStatus();
		$settings = $status->settings();

		if (!$settings instanceof Settings) {
			$this->stderr("Lettermint is not the transport of the email settings.\n", Console::FG_RED);

			return ExitCode::CONFIG;
		}

		$report = $status->health($settings, fresh: true);

		if ($this->json) {
			$this->stdout(Json::encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		} else {
			$this->printReport($report);
		}

		return $this->failed($report->status) ? ExitCode::UNAVAILABLE : ExitCode::OK;
	}

	private function failed(CheckStatus $status): bool
	{
		return $status === CheckStatus::Error || ($this->strict && $status === CheckStatus::Warning);
	}

	private function printReport(HealthReport $report): void
	{
		// The 0 only satisfies `max()`; there's always a token check.
		$width = max([0, ...array_map(static fn(Check $check): int => mb_strlen($check->id), $report->checks)]); // @pest-mutate-ignore

		foreach ($report->checks as $check) {
			$this->stdout(str_pad($check->status->value, 8), $this->color($check->status), Console::BOLD);
			$this->stdout(str_pad($check->id, $width + 2));
			$this->stdout($check->text() . "\n");
		}

		$this->stdout("\nStatus: ");
		$this->stdout($report->status->value, $this->color($report->status), Console::BOLD);
		$this->stdout("\n");
	}

	private function color(CheckStatus $status): int
	{
		return match ($status) {
			CheckStatus::Ok => Console::FG_GREEN,
			CheckStatus::Warning => Console::FG_YELLOW,
			CheckStatus::Error => Console::FG_RED,
		};
	}
}
