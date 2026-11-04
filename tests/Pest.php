<?php

use GuzzleHttp\Psr7\Response;
use SomehowDigital\Craft\Lettermint\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

/**
 * A response of Lettermint with the data as JSON.
 */
function jsonResponse(mixed $data, int $status = 200): Response
{
	return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
}
