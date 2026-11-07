<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\mail\transportadapters\BaseTransportAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class Adapter extends BaseTransportAdapter
{
	/**
	 * The fewest characters of the health token, which keeps it from being guessed.
	 */
	public const HEALTH_TOKEN_MIN_LENGTH = 16;

	/**
	 * The settings that can be set through environment variables.
	 */
	private const ENV_ATTRIBUTES = ['token', 'teamToken', 'projectId', 'webhookSecret', 'healthToken', 'route', 'tags'];

	public static function displayName(): string
	{
		return 'Lettermint';
	}

	public ?string $token = null;

	/**
	 * The team API token, for the team API of Lettermint (Pro edition).
	 */
	public ?string $teamToken = null;

	/**
	 * The ID of the Lettermint project of the token, which the utility shows (Pro edition).
	 *
	 * Lettermint does not tell the project of a token, so it is set here. Without it, only the team is shown.
	 */
	public ?string $projectId = null;

	/**
	 * The signing secret of the Lettermint webhook that posts to the webhook endpoint (Pro edition).
	 */
	public ?string $webhookSecret = null;

	/**
	 * The token that requests to the health endpoint authenticate with (Pro edition).
	 */
	public ?string $healthToken = null;

	public ?string $route = null;

	/**
	 * Tags as comma-separated name=value pairs, e.g. `environment=production, campaign=welcome`.
	 */
	public ?string $tags = null;

	/**
	 * The route and tags of each site, keyed by site UID (Pro edition).
	 *
	 * @var array<string,array{route?:string,tags?:string}>
	 */
	public array $sites = [];

	public function init(): void
	{
		parent::init();

		// Craft stores the posted settings as they are, so they are normalized when the adapter is created.
		$this->sites = Sites::normalize($this->sites);
	}

	public function attributeLabels(): array
	{
		return [
			'token' => Craft::t('lettermint', 'Project API Token'),
			'teamToken' => Craft::t('lettermint', 'Team API Token'),
			'projectId' => Craft::t('lettermint', 'Project'),
			'webhookSecret' => Craft::t('lettermint', 'Webhook Secret'),
			'healthToken' => Craft::t('lettermint', 'Health Token'),
			'route' => Craft::t('lettermint', 'Route Slug'),
			'tags' => Craft::t('lettermint', 'Tags'),
			'sites' => Craft::t('lettermint', 'Sites'),
		];
	}

	public function behaviors(): array
	{
		$behaviors = parent::behaviors();

		$behaviors['parser'] = [
			'class' => EnvAttributeParserBehavior::class,
			'attributes' => self::ENV_ATTRIBUTES,
		];

		return $behaviors;
	}

	/**
	 * @return list<array<mixed>>
	 */
	public function rules(): array
	{
		return [
			[['token'], 'required'],
			// The settings are typed as strings already, so they only need trimming.
			[self::ENV_ATTRIBUTES, 'trim'],
			// The settings hold their parsed value while they are validated, and empty ones are not validated.
			[['tags'], fn() => $this->addErrors(['tags' => Tags::errors($this->tags)])],
			// The health token is only validated as a non-empty string, though its type needs the cast.
			[['healthToken'], fn() => $this->addErrors(['healthToken' => $this->healthTokenErrors((string) $this->healthToken)])], // @pest-mutate-ignore: RemoveStringCast
			[['sites'], 'filter', 'filter' => Sites::normalize(...)],
			[['sites'], fn() => $this->addErrors(['sites' => Sites::tagErrors($this->sites, $this->getTags())])],
		];
	}

	/**
	 * The errors of the health token: its length, and whether it can be sent as a bearer token.
	 *
	 * @return list<string>
	 */
	private function healthTokenErrors(string $token): array
	{
		$errors = [];

		if (mb_strlen($token) < self::HEALTH_TOKEN_MIN_LENGTH) {
			$errors[] = Craft::t('lettermint', 'The health token must be at least {min} characters long.', [
				'min' => self::HEALTH_TOKEN_MIN_LENGTH,
			]);
		}

		if (preg_match('/\s/u', $token) === 1) {
			$errors[] = Craft::t('lettermint', 'The health token cannot contain spaces.');
		}

		return $errors;
	}

	/**
	 * The project (sending) token, with environment variables resolved, or null when it has none.
	 *
	 * It is kept untrimmed, as the transport sends it as it is.
	 */
	public function getToken(): ?string
	{
		$token = App::parseEnv($this->token);

		return is_string($token) && $token !== '' ? $token : null;
	}

	/**
	 * The route, with environment variables resolved, or null when it has none.
	 */
	public function getRoute(): ?string
	{
		return Env::value($this->route);
	}

	/**
	 * The tags, with environment variables resolved.
	 *
	 * @return list<array{name:string,value:string}>
	 */
	public function getTags(): array
	{
		return Tags::parse(App::parseEnv($this->tags));
	}

	/**
	 * The route of a site, with environment variables resolved, or null when it has none.
	 */
	public function getSiteRoute(string $siteUid): ?string
	{
		return Env::value($this->sites[$siteUid]['route'] ?? null);
	}

	/**
	 * The tags of a site, with environment variables resolved.
	 *
	 * @return list<array{name:string,value:string}>
	 */
	public function getSiteTags(string $siteUid): array
	{
		return Tags::parse(App::parseEnv($this->sites[$siteUid]['tags'] ?? null));
	}

	/**
	 * The team API token, with environment variables resolved, or null when it has none or in the standard edition.
	 */
	public function getTeamToken(): ?string
	{
		return $this->proValue($this->teamToken);
	}

	/**
	 * The project ID, with environment variables resolved, or null when it has none or in the standard edition.
	 */
	public function getProjectId(): ?string
	{
		return $this->proValue($this->projectId);
	}

	/**
	 * The webhook signing secret, with environment variables resolved, or null when it has none or in the standard edition.
	 */
	public function getWebhookSecret(): ?string
	{
		return $this->proValue($this->webhookSecret);
	}

	/**
	 * The health token, with environment variables resolved, or null when it has none or in the standard edition.
	 */
	public function getHealthToken(): ?string
	{
		return $this->proValue($this->healthToken);
	}

	/**
	 * A setting of the pro edition, with environment variables resolved, or null when it is empty or in the standard edition.
	 */
	private function proValue(?string $value): ?string
	{
		return Plugin::isPro() ? Env::value($value) : null;
	}

	public function getSettingsHtml(): string
	{
		return SettingsPage::render($this);
	}

	public function defineTransport(): AbstractTransport
	{
		$options = array_filter([
			'route' => $this->getRoute(),
			'tags' => $this->getTags(),
		], static fn(mixed $option): bool => $option !== null && $option !== []);

		$dispatcher = null;

		if (Plugin::isPro() && $this->sites !== []) {
			$dispatcher = new EventDispatcher();
			$dispatcher->addListener(MessageEvent::class, new SiteOptions($this));
		}

		return ClientFactory::transport($this->getToken() ?? '', $dispatcher, $options);
	}
}
