<?php

declare(strict_types=1);

use craft\enums\CmsEdition;
use craft\mail\transportadapters\Sendmail;
use craft\web\View;
use SomehowDigital\Craft\Lettermint\Edition;
use SomehowDigital\Craft\Lettermint\Plugin;
use SomehowDigital\Craft\Lettermint\Setup;
use SomehowDigital\Craft\Lettermint\Widget;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * Forgets the data that the utility keeps for the request, as a new request would, leaving the cache as it is.
 */
function forgetUtilityData(): void
{
	plugin()->set('team', Plugin::config()['components']['team']);
}

/**
 * Requests the refresh of the utility in the control panel, and returns the response.
 */
function refreshUtility(string $method = 'POST', ?string $redirect = 'dashboard', bool $cp = true): Response
{
	$_SERVER['REQUEST_METHOD'] = $method;

	$request = webRequest();
	$request->setIsCpRequest($cp);
	$request->enableCsrfValidation = false;
	$request->setBodyParams($redirect !== null ? ['redirect' => Craft::$app->getSecurity()->hashData($redirect)] : []);

	try {
		Craft::$app->runAction('lettermint/utility/refresh');
	} finally {
		unset($_SERVER['REQUEST_METHOD']);
	}

	return webResponse();
}

beforeEach(function (): void {
	Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
	plugin()->edition = Edition::Pro->value;
	signIn();

	lettermintEmail(['token' => 'secret', 'teamToken' => 'team-secret', 'projectId' => 'project-1']);
	$this->requests = $this->http(teamApiResponses(TEAM_API_RESPONSES));
});

it('caches the data for the utility and the widget', function (): void {
	utilityHtml();
	$count = count($this->requests);
	forgetUtilityData();

	(new Widget())->getBodyHtml();
	forgetUtilityData();
	utilityHtml();

	expect($count)->toBeGreaterThan(0);
	expect($this->requests)->toHaveCount($count);
});

it('loads the data again right away for other settings', function (): void {
	utilityHtml();
	$count = count($this->requests);
	forgetUtilityData();

	lettermintEmail(['token' => 'secret', 'teamToken' => 'other-secret', 'projectId' => 'project-1']);
	utilityHtml();

	expect($this->requests)->toHaveCount($count * 2);
});

it('loads the data again for another version of the plugin, which may shape it differently', function (): void {
	utilityHtml();
	$count = count($this->requests);
	forgetUtilityData();

	plugin()->version = '99.0.0';
	utilityHtml();

	expect($this->requests)->toHaveCount($count * 2);
});

it('loads the data again once it is refreshed', function (): void {
	utilityHtml();
	$count = count($this->requests);

	plugin()->getTeam()->refresh();
	utilityHtml();

	expect($this->requests)->toHaveCount($count * 2);
});

it('refreshes the data from the control panel and goes back', function (): void {
	utilityHtml();
	$count = count($this->requests);

	$response = refreshUtility();
	utilityHtml();

	expect($response->getStatusCode())->toBe(302);
	expect($response->getHeaders()->get('Location'))->toContain('dashboard');
	expect($this->requests)->toHaveCount($count * 2);
});

it('only refreshes with a post request', function (): void {
	refreshUtility('GET');
})->throws(MethodNotAllowedHttpException::class);

it('has no refresh in the standard edition', function (): void {
	plugin()->edition = Edition::Standard->value;

	refreshUtility();
})->throws(ForbiddenHttpException::class);

it('only refreshes for users who may open the utility', function (): void {
	// Users of Craft Solo may do everything.
	Craft::$app->edition = CmsEdition::Pro;
	signIn(admin: false);

	refreshUtility();
})->throws(ForbiddenHttpException::class);

it('goes back to the utility without a page to go back to', function (): void {
	$response = refreshUtility(redirect: null);

	expect($response->getHeaders()->get('Location'))->toEndWith('/utilities/lettermint');
});

it('only refreshes from the control panel', function (): void {
	refreshUtility(cp: false);
})->throws(BadRequestHttpException::class);

it('keeps the data for the app without a cache', function (): void {
	withoutCache();

	utilityHtml();
	$count = count($this->requests);
	plugin()->getTeam()->data();

	expect($count)->toBeGreaterThan(0);
	expect($this->requests)->toHaveCount($count);
});

it('refreshes the data without a cache', function (): void {
	withoutCache();

	utilityHtml();
	$count = count($this->requests);
	plugin()->getTeam()->refresh();
	utilityHtml();

	expect($this->requests)->toHaveCount($count * 2);
});

it('refreshes the data without Lettermint as the transport', function (): void {
	Craft::$app->getProjectConfig()->set('email', ['fromEmail' => 'hello@acme.test', 'transportType' => Sendmail::class]);

	expect(plugin()->getTeam()->data()->setup)->toBe(Setup::Transport);

	plugin()->getTeam()->refresh();

	expect(plugin()->getTeam()->data()->setup)->toBe(Setup::Transport);
});
