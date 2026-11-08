<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\Support;

use craft\errors\SiteNotFoundException;
use craft\models\Site;
use craft\services\Sites as SitesService;

/**
 * Serves test-added sites instead of the database's.
 */
class Sites extends SitesService
{
	/** @var list<Site> */
	private array $added = [];

	public function add(Site $site): void
	{
		$this->added[] = $site;
	}

	/**
	 * @return list<Site>
	 */
	public function getAllSites(?bool $withDisabled = null): array
	{
		return $withDisabled === true
			? $this->added
			: array_values(array_filter($this->added, static fn(Site $site): bool => $site->getEnabled() === true));
	}

	/**
	 * The added primary site, as Craft always has one.
	 */
	public function getPrimarySite(): Site
	{
		foreach ($this->added as $site) {
			if ($site->primary) {
				return $site;
			}
		}

		return parent::getPrimarySite();
	}

	public function getSiteByUid(string $uid, ?bool $withDisabled = null): Site
	{
		foreach ($this->getAllSites($withDisabled) as $site) {
			if ($site->uid === $uid) {
				return $site;
			}
		}

		throw new SiteNotFoundException("Site with UID “{$uid}” not found!");
	}
}
