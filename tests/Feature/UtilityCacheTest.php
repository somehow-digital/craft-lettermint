<?php

use craft\enums\CmsEdition;
use craft\web\View;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\Utility;
use SomehowDigital\Craft\Lettermint\Widget;
use yii\web\MethodNotAllowedHttpException;
use yii\web\ForbiddenHttpException;

/**
 * Forgets the data that the utility keeps for the app, as a new request would, leaving the cache as it is.
 */
function forgetUtilityData(): void
{
	(new ReflectionProperty(Utility::class, 'data'))->setValue(null, null);
}

/**
 * Requests the refresh of the utility in the control panel, and returns the response.
 */
function refreshUtility(string $method = 'POST', string $redirect = 'dashboard'): yii\web\Response
{
	$_SERVER['REQUEST_METHOD'] = $method;

	$request = Craft::$app->getRequest();
	$request->setIsCpRequest(true);
	$request->enableCsrfValidation = false;
	$request->setBodyParams(['redirect' => Craft::$app->getSecurity()->hashData($redirect)]);

	try {
		Craft::$app->runAction('lettermint/utility/refresh');
	} finally {
		unset($_SERVER['REQUEST_METHOD']);
	}

	return Craft::$app->getResponse();
}

beforeEach(function () {
	Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
	Plugin::getInstance()->edition = Plugin::EDITION_PRO;
	signIn();

	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'project' => 'project-1']);
	$this->requests = $this->http(teamApiResponses(TEAM_API_RESPONSES));
});

it('caches the data for the utility and the widget', function () {
	utilityHtml();
	$count = count($this->requests);
	forgetUtilityData();

	(new Widget())->getBodyHtml();
	forgetUtilityData();
	utilityHtml();

	expect($count)->toBeGreaterThan(0);
	expect($this->requests)->toHaveCount($count);
});

it('loads the data again right away for other settings', function () {
	utilityHtml();
	$count = count($this->requests);
	forgetUtilityData();

	lettermintEmail(['token' => 'secret', 'teamToken' => 'other-secret', 'project' => 'project-1']);
	utilityHtml();

	expect($this->requests)->toHaveCount($count * 2);
});

it('loads the data again for another version of the plugin, which may shape it differently', function () {
	utilityHtml();
	$count = count($this->requests);
	forgetUtilityData();

	Plugin::getInstance()->version = '99.0.0';
	utilityHtml();

	expect($this->requests)->toHaveCount($count * 2);
});

it('loads the data again once it is refreshed', function () {
	utilityHtml();
	$count = count($this->requests);

	Utility::refresh();
	utilityHtml();

	expect($this->requests)->toHaveCount($count * 2);
});

it('refreshes the data from the control panel and goes back', function () {
	utilityHtml();
	$count = count($this->requests);

	$response = refreshUtility();
	utilityHtml();

	expect($response->getStatusCode())->toBe(302);
	expect($response->getHeaders()->get('Location'))->toContain('dashboard');
	expect($this->requests)->toHaveCount($count * 2);
});

it('only refreshes with a post request', function () {
	refreshUtility('GET');
})->throws(MethodNotAllowedHttpException::class);

it('has no refresh in the standard edition', function () {
	Plugin::getInstance()->edition = Plugin::EDITION_STANDARD;

	refreshUtility();
})->throws(ForbiddenHttpException::class);

it('only refreshes for users who may open the utility', function () {
	// Users of Craft Solo may do everything.
	Craft::$app->edition = CmsEdition::Pro;
	signIn(admin: false);

	refreshUtility();
})->throws(ForbiddenHttpException::class);
