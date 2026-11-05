<?php

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
	$controller = new class('health', Plugin::getInstance()) extends HealthController {
		public string $out = '';
		public string $err = '';

		public function stdout($string)
		{
			$this->out .= $string;

			return strlen($string);
		}

		public function stderr($string)
		{
			$this->err .= $string;

			return strlen($string);
		}
	};

	$code = $controller->runAction('index', $params);

	return ['code' => $code, 'stdout' => $controller->out, 'stderr' => $controller->err];
}

beforeEach(function () {
	Plugin::getInstance()->edition = Plugin::EDITION_PRO;
});

it('lists the checks and succeeds', function () {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'project-1']);
	$this->http(healthResponses());

	['code' => $code, 'stdout' => $stdout] = runHealthCommand();

	expect($code)->toBe(ExitCode::OK);
	expect($stdout)
		->toContain('ok      token      Lettermint accepts the project API token.')
		->toContain('ok      teamToken  Lettermint accepts the team API token.')
		->toEndWith("\nStatus: ok\n");
});

it('fails with an error', function () {
	healthEmail([]);
	$this->http(new Response(401));

	['code' => $code, 'stdout' => $stdout] = runHealthCommand();

	expect($code)->toBe(ExitCode::UNAVAILABLE);
	expect($stdout)
		->toContain('error   token  Lettermint rejects the project API token.')
		->toEndWith("\nStatus: error\n");
});

it('fails with a warning only when strict', function (bool $strict, int $expected) {
	healthEmail(['teamToken' => 'team-secret', 'project' => 'project-1']);

	$responses = HEALTH_RESPONSES;
	$responses['/projects/project-1']['delivery_mode'] = 'sandbox';
	$this->http(healthResponses($responses));

	expect(runHealthCommand(['strict' => $strict])['code'])->toBe($expected);
})->with([
	'default' => [false, ExitCode::OK],
	'strict' => [true, ExitCode::UNAVAILABLE],
]);

it('outputs the report as JSON', function () {
	healthEmail([]);
	$this->http(healthResponses());

	$data = json_decode(runHealthCommand(['json' => true])['stdout'], true);

	expect($data['status'])->toBe('ok');
	expect($data['checks'])->toBe([
		['id' => 'token', 'status' => 'ok', 'text' => 'Lettermint accepts the project API token.'],
	]);
});

it('needs no health token', function () {
	healthEmail(['healthToken' => null]);
	$this->http(healthResponses());

	expect(runHealthCommand()['code'])->toBe(ExitCode::OK);
});

it('checks again without the cache', function () {
	healthEmail([]);
	$requests = $this->http(healthResponses());

	runHealthCommand();
	runHealthCommand();

	expect($requests)->toHaveCount(2);
});

it('fails when Lettermint is not the transport', function () {
	['code' => $code, 'stderr' => $stderr] = runHealthCommand();

	expect($code)->toBe(ExitCode::CONFIG);
	expect($stderr)->toBe("Lettermint is not the transport of the email settings.\n");
});

it('fails in the standard edition', function () {
	Plugin::getInstance()->edition = Plugin::EDITION_STANDARD;
	healthEmail([]);

	['code' => $code, 'stderr' => $stderr] = runHealthCommand();

	expect($code)->toBe(ExitCode::CONFIG);
	expect($stderr)->toBe("The health check requires the Pro edition.\n");
});
