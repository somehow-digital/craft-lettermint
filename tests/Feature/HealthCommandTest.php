<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use SomehowDigital\Craft\Lettermint\console\controllers\HealthController;
use SomehowDigital\Craft\Lettermint\Plugin;
use yii\console\ExitCode;

/**
 * Runs the command and returns its exit code and output, without colors.
 *
 * @param array<string,mixed> $params
 * @return array{code:int,stdout:string,stderr:string}
 */
function runHealthCommand(array $params = []): array
{
	$controller = new class ('health', plugin()) extends HealthController {
		public string $out = '';

		public string $err = '';

		public function stdout($string): int
		{
			$this->out .= $string;

			return strlen($string);
		}

		public function stderr($string): int
		{
			$this->err .= $string;

			return strlen($string);
		}
	};

	$code = $controller->runAction('index', $params);

	return ['code' => $code, 'stdout' => $controller->out, 'stderr' => $controller->err];
}

beforeEach(function (): void {
	plugin()->edition = Plugin::EDITION_PRO;
});

it('lists the checks and succeeds', function (): void {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'project-1']);
	$this->http(healthResponses());

	['code' => $code, 'stdout' => $stdout] = runHealthCommand();

	expect($code)->toBe(ExitCode::OK);
	expect($stdout)
		->toStartWith("ok      token      Lettermint accepts the project API token.\nok      teamToken  Lettermint accepts the team API token.\n")
		->toEndWith("\nStatus: ok\n");
});

it('fails with an error', function (): void {
	healthEmail([]);
	$this->http(new Response(401));

	['code' => $code, 'stdout' => $stdout] = runHealthCommand();

	expect($code)->toBe(ExitCode::UNAVAILABLE);
	expect($stdout)
		->toContain('error   token  Lettermint rejects the project API token, or does not allow it from the IP address of this server.')
		->toEndWith("\nStatus: error\n");
});

it('fails with a warning only when strict', function (bool $strict, int $expected): void {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'project-1']);

	$responses = HEALTH_RESPONSES;
	$responses['/projects/project-1']['delivery_mode'] = 'sandbox';
	$this->http(healthResponses($responses));

	expect(runHealthCommand(['strict' => $strict])['code'])->toBe($expected);
})->with([
	'default' => [false, ExitCode::OK],
	'strict' => [true, ExitCode::UNAVAILABLE],
]);

it('outputs the report as JSON', function (): void {
	healthEmail([]);
	$this->http(healthResponses());

	$stdout = runHealthCommand(['json' => true])['stdout'];
	$data = jsonArray($stdout);

	expect($stdout)->toStartWith("{\n    \"status\": \"ok\",\n")->toEndWith("\n}\n");

	expect($data['status'])->toBe('ok');
	expect($data['checks'])->toBe([
		['id' => 'token', 'status' => 'ok', 'text' => 'Lettermint accepts the project API token.'],
	]);
});

it('needs no health token', function (): void {
	healthEmail(['healthToken' => null]);
	$this->http(healthResponses());

	expect(runHealthCommand()['code'])->toBe(ExitCode::OK);
});

it('checks again without the cache', function (): void {
	healthEmail([]);
	$requests = $this->http(healthResponses());

	runHealthCommand();
	runHealthCommand();

	expect($requests)->toHaveCount(2);
});

it('fails when Lettermint is not the transport', function (): void {
	['code' => $code, 'stderr' => $stderr] = runHealthCommand();

	expect($code)->toBe(ExitCode::CONFIG);
	expect($stderr)->toBe("Lettermint is not the transport of the email settings.\n");
});

it('fails in the standard edition', function (): void {
	plugin()->edition = Plugin::EDITION_STANDARD;
	healthEmail([]);

	['code' => $code, 'stderr' => $stderr] = runHealthCommand();

	expect($code)->toBe(ExitCode::CONFIG);
	expect($stderr)->toBe("The health check requires the Pro edition.\n");
});

it('takes the options of console commands', function (): void {
	expect((new HealthController('health', plugin()))->options('index'))->toContain('interactive', 'color', 'json', 'strict');
});
