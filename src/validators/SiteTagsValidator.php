<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\validators;

use Craft;
use craft\helpers\App;
use SomehowDigital\Craft\Lettermint\helpers\Sites;
use SomehowDigital\Craft\Lettermint\helpers\TagList;
use yii\base\Model;
use yii\validators\Validator;

/**
 * Validates each site's tags, prefixing errors with the site name.
 *
 * Site tags are counted together with `tagsAttribute`, as they're merged when sending.
 *
 * @internal
 */
final class SiteTagsValidator extends Validator
{
	/**
	 * The adapter tags attribute.
	 */
	public string $tagsAttribute = 'tags';

	/**
	 * Error prefixes by site UID; the UID is used for unnamed sites.
	 *
	 * @var array<string,string>
	 */
	public array $siteNames = [];

	/**
	 * @param Model $model
	 * @param string $attribute
	 */
	public function validateAttribute($model, $attribute): void
	{
		$names = TagList::names($model[$this->tagsAttribute]);

		// Normalizing also types them.
		foreach (Sites::normalize($model[$attribute]) as $uid => $options) {
			$site = $this->siteNames[$uid] ?? $uid;

			foreach ($this->errors(App::parseEnv($options['tags'] ?? null), $names) as $error) {
				$model->addError($attribute, "{$site}: {$error}");
			}
		}
	}

	/**
	 * @param list<string> $names The adapter tag names.
	 * @return list<string>
	 */
	private function errors(mixed $siteTags, array $names): array
	{
		$errors = TagsValidator::errors($siteTags);
		$merged = array_unique([...$names, ...TagList::names($siteTags)]);

		// Too many adapter tags are reported on that attribute.
		if ($errors === [] && count($names) <= TagsValidator::MAX && count($merged) > TagsValidator::MAX) {
			$errors[] = Craft::t('lettermint', 'There can be at most {max} tags together with the tags above.', ['max' => TagsValidator::MAX]);
		}

		return $errors;
	}
}
