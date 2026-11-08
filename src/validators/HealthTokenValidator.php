<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\validators;

use Craft;
use yii\base\Model;
use yii\validators\Validator;

/**
 * Validates that a health token is long enough and valid as a bearer token.
 *
 * @internal
 */
final class HealthTokenValidator extends Validator
{
	public const MIN_LENGTH = 16;

	/**
	 * @param Model $model
	 * @param string $attribute
	 */
	public function validateAttribute($model, $attribute): void
	{
		$value = $model[$attribute];
		// Only narrows the type; empty values are skipped.
		$token = is_string($value) ? $value : ''; // @pest-mutate-ignore

		if (mb_strlen($token) < self::MIN_LENGTH) {
			$model->addError($attribute, Craft::t('lettermint', 'The health token must be at least {min} characters long.', [
				'min' => self::MIN_LENGTH,
			]));
		}

		if (preg_match('/\s/u', $token) === 1) {
			$model->addError($attribute, Craft::t('lettermint', 'The health token cannot contain spaces.'));
		}
	}
}
