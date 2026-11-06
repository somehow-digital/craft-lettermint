<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\Support;

use ErrorException;

/**
 * A warning of PHP that fails the test it was raised in.
 */
final class WarningException extends ErrorException {}
