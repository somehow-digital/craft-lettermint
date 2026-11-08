<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\api;

use Lettermint\Exceptions\WebhookVerificationException as SdkWebhookVerificationException;
use Lettermint\Webhook;
use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\base\WebhookVerifierInterface;
use SomehowDigital\Craft\Lettermint\errors\WebhookVerificationException;
use SomehowDigital\Craft\Lettermint\models\WebhookPayload;

/**
 * Verifies the deliveries of a Lettermint webhook with the SDK.
 *
 * @internal
 */
final readonly class WebhookVerifier implements WebhookVerifierInterface
{
	public function verify(#[SensitiveParameter] string $secret, string $body, array $headers): WebhookPayload
	{
		try {
			$payload = (new Webhook($secret))->verify($body, $headers);
		} catch (SdkWebhookVerificationException $sdkWebhookVerificationException) {
			throw new WebhookVerificationException($sdkWebhookVerificationException->getMessage(), 0, $sdkWebhookVerificationException); // @pest-mutate-ignore: IncrementInteger, DecrementInteger
		}

		return new WebhookPayload($payload->event, $payload->id, $payload->timestamp, $payload->data);
	}
}
