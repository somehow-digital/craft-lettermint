<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\controllers;

use craft\web\Controller;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\utilities\LettermintUtility;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Refreshes the cached data of the utility and the widget (Pro edition).
 */
class UtilityController extends Controller
{
	public function actionRefresh(): Response
	{
		$this->requirePostRequest();
		$this->requireCpRequest();

		if (!LettermintUtility::isAccessible()) {
			throw new ForbiddenHttpException();
		}

		Plugin::instance()->getUtility()->refresh();

		return $this->redirectToPostedUrl(null, LettermintUtility::path());
	}
}
