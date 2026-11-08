<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\validators;

use Craft;
use yii\base\Model;
use yii\validators\Validator;

/**
 * Validates a health token: its length, which keeps it from being guessed, and whether it can be sent as a bearer token.
 *
 * @internal
 */
final class HealthTokenValidator extends Validator
{
	/**
	 * The fewest characters of a health token.
	 */
	public const MIN_LENGTH = 16;

	/**
	 * @param Model $model
	 * @param string $attribute
	 */
	public function validateAttribute($model, $attribute): void
	{
		$value = $model[$attribute];
		// Empty values are not validated, so the value is a string here, though its type needs the check.
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
