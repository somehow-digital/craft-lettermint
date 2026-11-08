<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\controllers;

use Craft;
use craft\web\Controller;
use SomehowDigital\Craft\Lettermint\errors\WebhookVerificationException;
use SomehowDigital\Craft\Lettermint\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Receives the deliveries of a Lettermint webhook, verifies their signature and fires their events (Pro edition).
 *
 * @internal
 */
final class WebhookController extends Controller
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

		if (!Plugin::isPro()) {
			throw new NotFoundHttpException();
		}

		// Without a secret, the endpoint does not exist either.
		$secret = Plugin::instance()->getMailSettings()->settings()?->webhookSecret;

		if ($secret === null) {
			throw new NotFoundHttpException();
		}

		$request = $this->request;
		$webhooks = Plugin::instance()->getWebhooks();

		try {
			$payload = $webhooks->verify($secret, $request->getRawBody(), $request->getHeaders()->toArray());
		} catch (WebhookVerificationException $webhookVerificationException) {
			Craft::warning("Rejected a Lettermint webhook delivery: {$webhookVerificationException->getMessage()}", 'lettermint');

			// Lettermint does not deliver again after a 401, as the delivery would fail again. Responses take their
			// status from the exception, not its code.
			throw new UnauthorizedHttpException('The webhook delivery could not be verified.', 0, $webhookVerificationException); // @pest-mutate-ignore: IncrementInteger, DecrementInteger
		}

		// The handlers run outside the verification, so their exceptions fail the delivery, which Lettermint delivers again.
		$webhooks->dispatch($payload);

		return $this->asRaw('');
	}
}
