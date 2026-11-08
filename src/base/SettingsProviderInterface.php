<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\base;

use SomehowDigital\Craft\Lettermint\models\Settings;

/**
 * A transport adapter that resolves its settings as they apply, which the services read without depending on it.
 *
 * @internal
 */
interface SettingsProviderInterface
{
	/**
	 * The settings as they apply, with environment variables resolved and the settings of the pro edition left out in
	 * the standard edition.
	 */
	public function resolve(): Settings;
}
