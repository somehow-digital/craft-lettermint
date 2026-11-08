<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\validators;

use Craft;
use SomehowDigital\Craft\Lettermint\helpers\TagList;
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
	 * The most tags Lettermint accepts for a message.
	 */
	public const MAX = 20;

	/**
	 * @param Model $model
	 * @param string $attribute
	 */
	public function validateAttribute($model, $attribute): void
	{
		// The errors are translated and formatted already, so they are added to the model as they are.
		$model->addErrors([$attribute => self::errors($model[$attribute])]);
	}

	/**
	 * The errors of tags as they are written, following the rules of Lettermint for tag names and values.
	 *
	 * @return list<string>
	 */
	public static function errors(mixed $tags): array
	{
		$errors = [];
		$names = [];

		foreach (TagList::split($tags) as [$name, $value]) {
			if ($value === null) {
				$errors[] = Craft::t('lettermint', '“{tag}” is not a name=value pair.', ['tag' => $name]);
				continue;
			}

			if (null !== $error = self::nameError($name, isset($names[$name]))) {
				$errors[] = $error;
			}

			if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) !== 1) {
				$errors[] = Craft::t('lettermint', 'Tag value “{value}” must be 1 to 64 letters, digits, underscores or hyphens.', ['value' => $value]);
			}

			// Only the keys are looked up, with `isset()`, which any value but null passes.
			$names[$name] = true; // @pest-mutate-ignore: TrueToFalse
		}

		if (count($names) > self::MAX) {
			$errors[] = Craft::t('lettermint', 'There can be at most {max} tags.', ['max' => self::MAX]);
		}

		return $errors;
	}

	/**
	 * The error of a tag name, or null when it is valid.
	 *
	 * @param bool $used Whether an earlier tag has the same name.
	 */
	private static function nameError(string $name, bool $used): ?string
	{
		return match (true) {
			preg_match('/^[A-Za-z0-9_-]{1,32}$/', $name) !== 1 => Craft::t('lettermint', 'Tag name “{name}” must be 1 to 32 letters, digits, underscores or hyphens.', ['name' => $name]),
			stripos($name, '__lettermint') === 0 => Craft::t('lettermint', 'Tag name “{name}” is reserved.', ['name' => $name]),
			$used => Craft::t('lettermint', 'Tag name “{name}” is used more than once.', ['name' => $name]),
			default => null,
		};
	}
}
