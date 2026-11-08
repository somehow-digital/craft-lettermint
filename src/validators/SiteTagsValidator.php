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
 * Validates the tags of each site, keyed by site UID, with environment variables resolved, prefixing the errors with
 * the name of the site.
 *
 * The tags of a site are also counted together with the tags of the attribute `tagsAttribute`, as they are merged when
 * sending.
 *
 * @internal
 */
final class SiteTagsValidator extends Validator
{
	/**
	 * The attribute of the tags that the tags of the sites are merged with.
	 */
	public string $tagsAttribute = 'tags';

	/**
	 * The names of the sites, also the disabled ones, keyed by site UID, which prefix their errors. A site without a
	 * name is named by its UID.
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

		// The sites are normalized before they are validated, which also types them.
		foreach (Sites::normalize($model[$attribute]) as $uid => $options) {
			$site = $this->siteNames[$uid] ?? $uid;

			foreach ($this->errors(App::parseEnv($options['tags'] ?? null), $names) as $error) {
				$model->addError($attribute, "{$site}: {$error}");
			}
		}
	}

	/**
	 * The errors of the tags of a site.
	 *
	 * @param list<string> $names The names of the tags of the attribute.
	 * @return list<string>
	 */
	private function errors(mixed $siteTags, array $names): array
	{
		$errors = TagsValidator::errors($siteTags);
		$merged = array_unique([...$names, ...TagList::names($siteTags)]);

		// Too many tags of the attribute are reported on the attribute itself.
		if ($errors === [] && count($names) <= TagsValidator::MAX && count($merged) > TagsValidator::MAX) {
			$errors[] = Craft::t('lettermint', 'There can be at most {max} tags together with the tags above.', ['max' => TagsValidator::MAX]);
		}

		return $errors;
	}
}
