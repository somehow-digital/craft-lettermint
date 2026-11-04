<?php

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use craft\mail\transportadapters\BaseTransportAdapter;
use Lettermint\SymfonyMailer\Transport\LettermintApiTransport;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

class Adapter extends BaseTransportAdapter
{
	public static function displayName(): string
	{
		return 'Lettermint';
	}

	public ?string $token = null;

	public ?string $route = null;

	/**
	 * The route of each site, keyed by site UID (Pro edition).
	 *
	 * @var array<string,array{route:string}>
	 */
	public array $sites = [];

	public function init(): void
	{
		parent::init();

		// Craft stores the posted settings as they are, so they are normalized when the adapter is created.
		$this->sites = $this->normalizeSites($this->sites);
	}

	public function attributeLabels(): array
	{
		return [
			'token' => Craft::t('lettermint', 'Token'),
			'route' => Craft::t('lettermint', 'Route'),
			'sites' => Craft::t('lettermint', 'Sites'),
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
			[['sites'], 'filter', 'filter' => $this->normalizeSites(...)],
		];
	}

	/**
	 * The route of a site, with environment variables resolved, or null when it has none.
	 */
	public function getSiteRoute(string $siteUid): ?string
	{
		$route = App::parseEnv($this->sites[$siteUid]['route'] ?? null);

		return is_string($route) && trim($route) !== '' ? trim($route) : null;
	}

	public function getSettingsHtml(): ?string
	{
		$rows = [];

		foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
			$rows[$site->uid] = [
				'heading' => $site->getName(),
				'route' => $this->sites[$site->uid]['route'] ?? '',
			];
		}

		return Craft::$app->getView()->renderTemplate('lettermint/settings', [
			'adapter' => $this,
			'pro' => self::isPro(),
			'siteRows' => $rows,
		]);
	}

	public function defineTransport(): AbstractTransport
	{
		$route = App::parseEnv($this->route);

		$dispatcher = null;

		if (self::isPro() && $this->sites !== []) {
			$dispatcher = new EventDispatcher();
			$dispatcher->addListener(MessageEvent::class, $this->applySiteRoute(...));
		}

		return new LettermintApiTransport(
			projectToken: App::parseEnv($this->token),
			dispatcher: $dispatcher,
			options: $route ? ['route' => $route] : [],
		);
	}

	/**
	 * Applies the route of the current site to a message, which Craft sets to the site of the message while sending.
	 *
	 * A route set on the message itself takes precedence over the one of the site.
	 */
	private function applySiteRoute(MessageEvent $event): void
	{
		$email = $event->getMessage();

		if (!$email instanceof Email) {
			return;
		}

		try {
			$site = Craft::$app->getSites()->getCurrentSite();
		} catch (SiteNotFoundException) {
			return;
		}

		$route = $this->getSiteRoute($site->uid);

		if ($route === null) {
			return;
		}

		$options = ['route' => $route];
		$headers = $email->getHeaders();

		if (null !== $header = $headers->get('X-LM-Options')) {
			$overrides = json_decode((string) $header->getBody(), true);

			// Invalid options are left to the transport, which rejects them.
			if (!is_array($overrides)) {
				return;
			}

			$options = array_replace($options, $overrides);
			$headers->remove('X-LM-Options');
		}

		$headers->addTextHeader('X-LM-Options', json_encode($options, JSON_THROW_ON_ERROR));
	}

	/**
	 * Reduces the sites to the routes that are set, dropping sites without one.
	 *
	 * @return array<string,array{route:string}>
	 */
	private function normalizeSites(mixed $sites): array
	{
		$normalized = [];

		foreach (is_array($sites) ? $sites : [] as $uid => $options) {
			if (!is_string($uid) || !is_array($options)) {
				continue;
			}

			$route = $options['route'] ?? null;

			if (is_string($route) && trim($route) !== '') {
				$normalized[$uid] = ['route' => trim($route)];
			}
		}

		return $normalized;
	}

	private static function isPro(): bool
	{
		return Plugin::getInstance()?->is(Plugin::EDITION_PRO) ?? false;
	}
}
