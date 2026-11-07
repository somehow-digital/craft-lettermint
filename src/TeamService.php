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

		// The adapter is created once, for both the key of the cache and the data.
		$adapter = MailSettings::adapter();
		$cache = Craft::$app->getCache();

		return $this->data = $adapter instanceof Adapter && $cache !== null
			? $cache->getOrSet($this->cacheKey($adapter), fn(): array => $this->fetch($adapter), self::CACHE_DURATION)
			: $this->fetch($adapter);
	}

	/**
	 * Clears the cached data, so it is loaded from Lettermint again.
	 */
	public function refresh(): void
	{
		// Without Lettermint as the transport, nothing is cached.
		if (($adapter = MailSettings::adapter()) instanceof Adapter) {
			Craft::$app->getCache()?->delete($this->cacheKey($adapter));
		}

		$this->data = null;
	}

	/**
	 * The key of the data cached for an adapter.
	 */
	private function cacheKey(Adapter $adapter): string
	{
		return MailSettings::cacheKey($adapter, 'utility');
	}

	/**
	 * Loads the data of the page from Lettermint, with the adapter of the email settings, or null when Lettermint is
	 * not the transport.
	 *
	 * @return array<string,mixed>
	 */
	private function fetch(?Adapter $adapter): array
	{
		$token = $adapter?->getTeamToken();
		$projectId = $adapter?->getProjectId();
		$sections = $token !== null ? $this->api($token)->overview($projectId) : null;
		$invalid = Overview::invalid($sections);
		$project = $sections !== null ? Overview::project($projectId, $sections) : null;
		$checks = $adapter instanceof Adapter ? Plugin::instance()->getHealth()->checks($adapter, $sections) : null;

		// The project and its domains are kept as the project, so they are not cached and passed to the page twice.
		if ($sections !== null) {
			unset($sections['project'], $sections['domains']);
		}

		return [
			// What is missing before the team can be shown, if anything.
			'setup' => match (true) {
				!$adapter instanceof Adapter => Setup::Transport,
				$token === null => Setup::Token,
				$invalid => Setup::Invalid,
				default => null,
			},
			// The checks of the health endpoint, by what the page shows them with.
			'checks' => $checks instanceof Checks ? [
				'token' => $checks->token(),
				'teamToken' => $checks->teamToken(),
				'project' => $checks->project(),
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
