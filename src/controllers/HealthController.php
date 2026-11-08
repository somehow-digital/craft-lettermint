<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\controllers;

use Craft;
use craft\web\Controller;
use SomehowDigital\Craft\Lettermint\enums\CheckStatus;
use SomehowDigital\Craft\Lettermint\helpers\HealthEndpoint;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\Plugin;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Health endpoint for monitoring (Pro edition).
 *
 * Authenticates with the health token as a bearer token or, for monitors without headers, the `key` query
 * parameter (Craft reserves `token`).
 *
 * @internal
 */
final class HealthController extends Controller
{
	/**
	 * Authenticated by the health token, and read-only.
	 */
	public $enableCsrfValidation = false;

	/**
	 * Also available while the system is offline.
	 */
	protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;

	public function actionIndex(): Response
	{
		if (!$this->request->getIsGet() && !$this->request->getIsHead()) {
			throw new MethodNotAllowedHttpException();
		}

		if (!Plugin::isPro()) {
			throw new NotFoundHttpException();
		}

		$status = Plugin::instance()->getStatus();
		$settings = $status->settings();

		// Without a token, the endpoint doesn't exist.
		$token = $settings?->healthToken;

		// The instanceof check only narrows the type.
		if (!$settings instanceof Settings || $token === null) { // @pest-mutate-ignore: InstanceOfToTrue
			throw new NotFoundHttpException();
		}

		// Hashing first hides the token length.
		if (!hash_equals(hash('sha256', $token), hash('sha256', $this->requestToken()))) {
			Craft::warning('Rejected a Lettermint health request with a missing or wrong token.', 'lettermint');

			throw new UnauthorizedHttpException('The health token is missing or wrong.');
		}

		$report = $status->health($settings);

		$response = $this->asJson($report);
		// For monitors that only check the status code.
		$response->setStatusCode($report->status === CheckStatus::Error ? 503 : 200);
		$response->getHeaders()->set('Cache-Control', 'no-store');

		return $response;
	}

	/**
	 * From the `Authorization` header or the query parameter.
	 */
	private function requestToken(): string
	{
		$header = $this->request->getHeaders()->get('Authorization');

		if (is_string($header) && preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) === 1) {
			return $matches[1];
		}

		$param = $this->request->getQueryParam(HealthEndpoint::QUERY_PARAM);

		// An empty string never matches the token.
		return is_string($param) ? $param : ''; // @pest-mutate-ignore: EmptyStringToNotEmpty
	}
}
