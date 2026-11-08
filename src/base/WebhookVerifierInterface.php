<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\base;

use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\errors\WebhookVerificationException;
use SomehowDigital\Craft\Lettermint\models\WebhookPayload;

/**
 * Verifies the signature of a delivery of a Lettermint webhook.
 *
 * The plugin binds it to its implementation in the container, so the services do not depend on the SDK of Lettermint.
 *
 * @internal
 */
interface WebhookVerifierInterface
{
	/**
	 * The payload of a delivery, once its signature is verified with the signing secret of the webhook.
	 *
	 * @param array<mixed> $headers The headers of the request.
	 * @throws WebhookVerificationException when the delivery is not genuine.
	 */
	public function verify(#[SensitiveParameter] string $secret, string $body, array $headers): WebhookPayload;
}
