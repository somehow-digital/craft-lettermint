<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\errors;

use Exception;

/**
 * A delivery of a Lettermint webhook whose signature could not be verified.
 *
 * @internal
 */
final class WebhookVerificationException extends Exception {}
