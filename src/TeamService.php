<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use DateTimeImmutable;
use SensitiveParameter;
use yii\base\Component;

/**
 * Loads the team and usage of the team API token, and the statistics and domains of the project of the token, for the
 * utility and the widget (Pro edition), and creates the clients of the team API, for the health checks as well.
 *
 * The plugin registers it as its `team` component, which projects can configure or replace through the plugin configs
 * of Craft, e.g. to read the team API differently.
 */
class TeamService extends Component
{
	/**
	 * The seconds the data is cached for, so the utility and the widget do not load it from Lettermint each time.
	 */
	public const CACHE_DURATION = 300;

	/**
	 * The data of the page for this request, as its content, toolbar and footer are rendered separately.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $data = null;

	/**
	 * A client of the team API, authenticated with the team token.
	 */
	public function api(#[SensitiveParameter] string $token): TeamApi
	{
		return new TeamApi($token);
	}

	/**
	 * The data of the page and the widget, from the cache when it was loaded within the last minutes.
	 *
	 * It is kept for the request as well, as the content, toolbar and footer are rendered separately.
	 *
	 * @return array<string,mixed>
	 */
	public function data(): array
	{
		if ($this->data !== null) {
			return $this->data;
		}

		$key = $this->cacheKey();
		$cache = Craft::$app->getCache();

		return $this->data = $key !== null && $cache !== null
			? $cache->getOrSet($key, fn(): array => $this->fetch(), self::CACHE_DURATION)
			: $this->fetch();
	}

	/**
	 * Clears the cached data, so it is loaded from Lettermint again.
	 */
	public function refresh(): void
	{
		if (null !== $key = $this->cacheKey()) {
			Craft::$app->getCache()?->delete($key);
		}

		$this->data = null;
	}

	/**
	 * The key of the cached data, or null when Lettermint is not the transport, as there is nothing to cache then.
	 */
	private function cacheKey(): ?string
	{
		$adapter = MailSettings::adapter();

		return $adapter instanceof Adapter ? MailSettings::cacheKey($adapter, 'utility') : null;
	}

	/**
	 * Loads the data of the page from Lettermint.
	 *
	 * @return array<string,mixed>
	 */
	private function fetch(): array
	{
		$adapter = MailSettings::adapter();
		$token = $adapter?->getTeamToken();
		$projectId = $adapter?->getProjectId();
		$sections = $token !== null ? $this->api($token)->overview($projectId) : null;
		$invalid = Overview::invalid($sections);
		$project = $sections !== null ? Overview::project($adapter, $projectId, $sections) : null;

		// The project and its domains are kept as the project, so they are not cached and passed to the page twice.
		if ($sections !== null) {
			unset($sections['project'], $sections['domains']);
		}

		return [
			// What is missing before the team can be shown, if anything.
			'setup' => match (true) {
				!$adapter instanceof Adapter => 'transport',
				$token === null => 'token',
				$invalid => 'invalid',
				default => null,
			},
			'tokens' => $adapter instanceof Adapter ? [
				'token' => TokenChecker::check($adapter->getToken())?->value,
				'teamToken' => Overview::teamTokenStatus($sections)?->value,
			] : null,
			'sections' => $sections,
			'usage' => Overview::usage($sections),
			'project' => $project,
			'statsDays' => TeamApi::STATS_DAYS,
			'dashboardUrl' => Utility::DASHBOARD_URL,
			'loadedAt' => new DateTimeImmutable(),
		];
	}
}
