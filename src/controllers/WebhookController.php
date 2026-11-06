<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\controllers;

use Craft;
use craft\web\Controller;
use Lettermint\Exceptions\WebhookVerificationException;
use Lettermint\Webhook;
use SomehowDigital\Craft\Lettermint\Adapter;
use SomehowDigital\Craft\Lettermint\Webhooks;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Receives the deliveries of a Lettermint webhook, verifies their signature and fires their events (Pro edition).
 */
class WebhookController extends Controller
{
	/**
	 * Lettermint signs its deliveries instead of sending a CSRF token.
	 */
	public $enableCsrfValidation = false;

	/**
	 * Deliveries are received while the system is offline as well, so no events are missed.
	 */
	protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;

	public function actionIndex(): Response
	{
		$this->requirePostRequest();

		// The secret is only set in the pro edition, so without either, the endpoint does not exist.
		$secret = Adapter::fromMailSettings()?->getWebhookSecret();

		if ($secret === null) {
			throw new NotFoundHttpException();
		}

		$request = $this->request;

		try {
			$payload = (new Webhook($secret))->verify($request->getRawBody(), $request->getHeaders()->toArray());
		} catch (WebhookVerificationException $exception) {
			Craft::warning("Rejected a Lettermint webhook delivery: {$exception->getMessage()}", 'lettermint');

			// Lettermint does not deliver again after a 401, as the delivery would fail again. Responses take their
			// status from the exception, not its code.
			throw new UnauthorizedHttpException('The webhook delivery could not be verified.', 0, $exception); // @pest-mutate-ignore: IncrementInteger, DecrementInteger
		}

		Webhooks::dispatch($payload);

		return $this->asRaw('');
	}
}
