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
		$data = $this->data();

		return $this->asJson([
			'content' => LettermintUtility::render('lettermint/utility/content', $data),
			'footer' => LettermintUtility::render('lettermint/utility/footer', $data),
		]);
	}

	/**
	 * The body of the widget.
	 */
	public function actionWidget(): Response
	{
		return $this->asJson(['widget' => LettermintUtility::render('lettermint/widget', $this->data())]);
	}

	/**
	 * The data of the utility and the widget, for a request of the control panel by a user who may open the utility.
	 */
	private function data(): UtilityData
	{
		$this->requireCpRequest();

		if (!LettermintUtility::isAccessible()) {
			throw new ForbiddenHttpException();
		}

		$utility = Plugin::instance()->getUtility();

		return $this->request->getIsPost() ? $utility->reload() : $utility->data();
	}
}
