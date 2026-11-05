<?php

namespace SomehowDigital\Craft\Lettermint\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Health;
use SomehowDigital\Craft\Lettermint\Plugin;
use yii\console\ExitCode;

/**
 * Checks whether emails can be sent with Lettermint, e.g. during a deployment (Pro edition).
 *
 * The checks are the ones of the health endpoint, but always run fresh, without the cache or the health token.
 */
class HealthController extends Controller
{
	/**
	 * The colors of each status.
	 */
	private const COLORS = [
		Health::OK => Console::FG_GREEN,
		Health::WARNING => Console::FG_YELLOW,
		Health::ERROR => Console::FG_RED,
	];

	/**
	 * @var bool Whether to output the report as JSON, like the health endpoint.
	 */
	public bool $json = false;

	/**
	 * @var bool Whether warnings fail the command as well.
	 */
	public bool $strict = false;

	public function options($actionID): array
	{
		return [...parent::options($actionID), 'json', 'strict'];
	}

	/**
	 * Checks whether emails can be sent with Lettermint.
	 *
	 * Exits with 0 for `ok` and `warning`, and 69 for `error`, or for `warning` with `--strict`.
	 */
	public function actionIndex(): int
	{
		if (!Plugin::isPro()) {
			$this->stderr("The health check requires the Pro edition.\n", Console::FG_RED);

			return ExitCode::CONFIG;
		}

		$adapter = Adapter::fromMailSettings();

		if ($adapter === null) {
			$this->stderr("Lettermint is not the transport of the email settings.\n", Console::FG_RED);

			return ExitCode::CONFIG;
		}

		$report = Health::check($adapter);

		if ($this->json) {
			$this->stdout(Json::encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		} else {
			$this->printReport($report);
		}

		return $this->failed($report['status']) ? ExitCode::UNAVAILABLE : ExitCode::OK;
	}

	/**
	 * Whether the status fails the command.
	 */
	private function failed(string $status): bool
	{
		return $status === Health::ERROR || ($this->strict && $status === Health::WARNING);
	}

	/**
	 * Outputs the checks and the overall status.
	 *
	 * @param array{status:string,checks:list<array{id:string,status:string,text:string}>} $report
	 */
	private function printReport(array $report): void
	{
		$width = max(array_map(static fn (array $check) => mb_strlen($check['id']), $report['checks']));

		foreach ($report['checks'] as $check) {
			$this->stdout(str_pad($check['status'], 8), self::COLORS[$check['status']], Console::BOLD);
			$this->stdout(str_pad($check['id'], $width + 2));
			$this->stdout($check['text'] . "\n");
		}

		$this->stdout("\nStatus: ");
		$this->stdout($report['status'], self::COLORS[$report['status']], Console::BOLD);
		$this->stdout("\n");
	}
}
