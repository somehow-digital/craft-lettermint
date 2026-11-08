<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\controllers;

use Craft;
use craft\web\Controller;
use Lettermint\Exceptions\WebhookVerificationException;
use Lettermint\Webhook;
use SomehowDigital\Craft\Lettermint\models\WebhookPayload;
use SomehowDigital\Craft\Lettermint\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Verifies Lettermint webhook deliveries and fires their events (Pro edition).
 *
 * @internal
 */
final class WebhookController extends Controller
{
	/**
	 * Deliveries are signed instead.
	 */
	public $enableCsrfValidation = false;

	/**
	 * Also receives deliveries while the system is offline.
	 */
	protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;

	public function actionIndex(): Response
	{
		$this->requirePostRequest();

		if (!Plugin::isPro()) {
			throw new NotFoundHttpException();
		}

		// Without a secret, the endpoint doesn't exist.
		$secret = Plugin::instance()->getStatus()->settings()?->webhookSecret;

		if ($secret === null) {
			throw new NotFoundHttpException();
		}

		$request = $this->request;

		try {
			$delivery = (new Webhook($secret))->verify($request->getRawBody(), $request->getHeaders()->toArray());
		} catch (WebhookVerificationException $webhookVerificationException) {
			Craft::warning("Rejected a Lettermint webhook delivery: {$webhookVerificationException->getMessage()}", 'lettermint');

			// Lettermint doesn't retry after a 401. The status comes from the exception class, not its code.
			throw new UnauthorizedHttpException('The webhook delivery could not be verified.', 0, $webhookVerificationException); // @pest-mutate-ignore: IncrementInteger, DecrementInteger
		}

		// Outside the try, so handler exceptions fail the delivery and Lettermint retries.
		Plugin::instance()->getWebhooks()->dispatch(new WebhookPayload($delivery->event, $delivery->id, $delivery->timestamp, $delivery->data));

		return $this->asRaw('');
	}
}
