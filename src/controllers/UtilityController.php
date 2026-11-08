<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\controllers;

use craft\web\Controller;
use SomehowDigital\Craft\Lettermint\models\UtilityData;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\utilities\LettermintUtility;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Loads the utility and widget data for their scripts, so pages don't wait for Lettermint (Pro edition).
 *
 * Actions return named parts that replace those on the page. GET loads stale data; POST (refresh) always reloads.
 *
 * @internal
 */
final class UtilityController extends Controller
{
	/**
	 * The utility's content and footer.
	 */
	public function actionContent(): Response
	{
		$data = $this->data();

		return $this->asJson([
			'content' => LettermintUtility::render('lettermint/utility/content', $data),
			'footer' => LettermintUtility::render('lettermint/utility/footer', $data),
		]);
	}

	public function actionWidget(): Response
	{
		return $this->asJson(['widget' => LettermintUtility::render('lettermint/widget', $this->data())]);
	}

	/**
	 * Requires a CP request by a user with access to the utility. POST reloads the data.
	 */
	private function data(): UtilityData
	{
		$this->requireCpRequest();

		if (!LettermintUtility::isAccessible()) {
			throw new ForbiddenHttpException();
		}

		$status = Plugin::instance()->getStatus();

		return $this->request->getIsPost() ? $status->reload() : $status->data();
	}
}
