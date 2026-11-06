<?php

// The constants that the bootstrap of the tests defines, which PHPStan does not run.
defined('CRAFT_VENDOR_PATH') || define('CRAFT_VENDOR_PATH', dirname(__DIR__, 2) . '/vendor');
