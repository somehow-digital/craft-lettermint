<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\controllers;

use craft\web\Controller;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\Utility;
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

		if (!Utility::isAccessible()) {
			throw new ForbiddenHttpException();
		}

		Plugin::instance()->getTeam()->refresh();

		return $this->redirectToPostedUrl(null, Utility::path());
	}
}
