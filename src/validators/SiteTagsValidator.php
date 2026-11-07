<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\validators;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use SomehowDigital\Craft\Lettermint\helpers\Sites;
use SomehowDigital\Craft\Lettermint\models\Tags;
use yii\base\Model;
use yii\validators\Validator;

/**
 * Validates the tags of each site, keyed by site UID, with environment variables resolved, prefixing the errors with
 * the name of the site.
 *
 * The tags of a site are also counted together with the tags of the attribute `tagsAttribute`, as they are merged when
 * sending.
 */
class SiteTagsValidator extends Validator
{
	/**
	 * The attribute of the tags that the tags of the sites are merged with.
	 */
	public string $tagsAttribute = 'tags';

	/**
	 * @param Model $model
	 * @param string $attribute
	 */
	public function validateAttribute($model, $attribute): void
	{
		$tags = Tags::parse($model[$this->tagsAttribute]);

		// The sites are normalized before they are validated, which also types them.
		foreach (Sites::normalize($model[$attribute]) as $uid => $options) {
			$errors = $this->errors(App::parseEnv($options['tags'] ?? null), $tags);

			// Only the sites with errors are looked up for their name.
			if ($errors === []) {
				continue;
			}

			$site = $this->name($uid);

			foreach ($errors as $error) {
				$model->addError($attribute, "{$site}: {$error}");
			}
		}
	}

	/**
	 * The errors of the tags of a site.
	 *
	 * @return list<string>
	 */
	private function errors(mixed $siteTags, Tags $tags): array
	{
		$errors = Tags::errors($siteTags);

		// Too many tags of the attribute are reported on the attribute itself.
		if ($errors === [] && count($tags) <= Tags::MAX && count($tags->merge(Tags::parse($siteTags))) > Tags::MAX) {
			$errors[] = Craft::t('lettermint', 'There can be at most {max} tags together with the tags above.', ['max' => Tags::MAX]);
		}

		return $errors;
	}

	/**
	 * The name of a site, also a disabled one, or its UID when there is no such site.
	 */
	private function name(string $uid): string
	{
		try {
			return Craft::$app->getSites()->getSiteByUid($uid, true)->getName();
		} catch (SiteNotFoundException) {
			return $uid;
		}
	}
}
