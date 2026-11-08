<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\Support;

use ErrorException;

/**
 * A PHP warning that fails the test.
 */
final class WarningException extends ErrorException {}
