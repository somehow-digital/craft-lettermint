<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\controllers;

use craft\web\Controller;
use SomehowDigital\Craft\Lettermint\models\UtilityData;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\services\UtilityService;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Loads the data of the utility and the widget (Pro edition) for their scripts, so their pages do not wait for
 * Lettermint.
 *
 * The actions return the parts that replace the ones of the page, by their name. A GET request loads the data unless
 * it is fresh, and a POST request, from the refresh button, loads it again in any case.
 *
 * @internal
 */
final class UtilityController extends Controller
{
	/**
	 * The content and the footer of the page of the utility.
	 */
	public function actionContent(): Response
	{
		$utility = $this->utility();
		$data = $this->data($utility);

		return $this->asJson([
			'content' => $utility->render('lettermint/utility/content', $data),
			'footer' => $utility->render('lettermint/utility/footer', $data),
		]);
	}

	/**
	 * The body of the widget.
	 */
	public function actionWidget(): Response
	{
		$utility = $this->utility();

		return $this->asJson(['widget' => $utility->render('lettermint/widget', $this->data($utility))]);
	}

	/**
	 * The utility service, for a request of the control panel by a user who may open the utility.
	 */
	private function utility(): UtilityService
	{
		$this->requireCpRequest();

		$utility = Plugin::instance()->getUtility();

		if (!$utility->isAccessible()) {
			throw new ForbiddenHttpException();
		}

		return $utility;
	}

	/**
	 * The data of the utility and the widget, loaded again for a post request, as the refresh button sends.
	 */
	private function data(UtilityService $utility): UtilityData
	{
		return $this->request->getIsPost() ? $utility->reload() : $utility->data();
	}
}
