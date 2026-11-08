<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\controllers;

use Craft;
use craft\web\Controller;
use SomehowDigital\Craft\Lettermint\enums\CheckStatus;
use SomehowDigital\Craft\Lettermint\models\Settings;
use SomehowDigital\Craft\Lettermint\Plugin;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Reports whether emails can be sent with Lettermint, for monitoring (Pro edition).
 *
 * Requests authenticate with the health token, as a bearer token or, for monitors that cannot send headers, as the
 * `key` query parameter. Craft takes the `token` query parameter for its own tokens.
 *
 * @internal
 */
final class HealthController extends Controller
{
	/**
	 * The query parameter of the health token.
	 */
	public const QUERY_PARAM = 'key';

	/**
	 * Monitors authenticate with the health token instead of a CSRF token, and the endpoint changes nothing.
	 */
	public $enableCsrfValidation = false;

	/**
	 * The system can be checked while it is offline as well.
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

		// Without a token, the endpoint does not exist either.
		$token = $settings?->healthToken;

		// Without settings, there is no token, though the type of the settings needs the check.
		if (!$settings instanceof Settings || $token === null) { // @pest-mutate-ignore: InstanceOfToTrue
			throw new NotFoundHttpException();
		}

		// Hashing both first keeps the comparison from revealing the length of the token.
		if (!hash_equals(hash('sha256', $token), hash('sha256', $this->requestToken()))) {
			Craft::warning('Rejected a Lettermint health request with a missing or wrong token.', 'lettermint');

			throw new UnauthorizedHttpException('The health token is missing or wrong.');
		}

		$report = $status->health($settings);

		$response = $this->asJson($report);
		// Monitors that only look at the status code see errors as the service being unavailable.
		$response->setStatusCode($report->status === CheckStatus::Error ? 503 : 200);
		$response->getHeaders()->set('Cache-Control', 'no-store');

		return $response;
	}

	/**
	 * The token of the request, from the `Authorization` header or the query parameter.
	 */
	private function requestToken(): string
	{
		$header = $this->request->getHeaders()->get('Authorization');

		if (is_string($header) && preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) === 1) {
			return $matches[1];
		}

		$param = $this->request->getQueryParam(self::QUERY_PARAM);

		// Without a token, any value that is not the token is rejected.
		return is_string($param) ? $param : ''; // @pest-mutate-ignore: EmptyStringToNotEmpty
	}
}
