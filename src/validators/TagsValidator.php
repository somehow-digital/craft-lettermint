<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\validators;

use SomehowDigital\Craft\Lettermint\models\Tags;
use yii\base\Model;
use yii\validators\Validator;

/**
 * Validates tags as they are written, following the rules of Lettermint for tag names and values.
 *
 * @internal
 */
final class TagsValidator extends Validator
{
	/**
	 * @param Model $model
	 * @param string $attribute
	 */
	public function validateAttribute($model, $attribute): void
	{
		// The errors are translated and formatted already, so they are added to the model as they are.
		$model->addErrors([$attribute => Tags::errors($model[$attribute])]);
	}
}
