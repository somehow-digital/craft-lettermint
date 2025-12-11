<?php

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\mail\transportadapters\BaseTransportAdapter;
use SomehowDigital\Symfony\Component\Mailer\Bridge\Lettermint\Transport\LettermintApiTransport;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class Adapter extends BaseTransportAdapter
{
	public static function displayName(): string
	{
		return 'Lettermint';
	}

	public ?string $token = null;

	public ?string $route = null;

	public function attributeLabels(): array
	{
		return [
			'token' => Craft::t('lettermint', 'Token'),
			'route' => Craft::t('lettermint', 'Route'),
		];
	}

	public function behaviors(): array
	{
		$behaviors = parent::behaviors();

		$behaviors['parser'] = [
			'class' => EnvAttributeParserBehavior::class,
			'attributes' => [
				'token',
				'route',
			],
		];

		return $behaviors;
	}

	public function rules(): array
	{
		return [
			[['token'], 'required'],
			[['token', 'route'], 'string'],
			[['token', 'route'], 'trim'],
		];
	}

	public function getSettingsHtml(): ?string
	{
		return Craft::$app->getView()->renderTemplate('lettermint/settings', [
			'adapter' => $this,
		]);
	}

	public function defineTransport(): AbstractTransport
	{
		return new LettermintApiTransport(App::parseEnv($this->token), App::parseEnv($this->route));
	}
}
