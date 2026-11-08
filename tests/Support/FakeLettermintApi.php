<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\Support;

use SensitiveParameter;
use SomehowDigital\Craft\Lettermint\base\LettermintApiInterface;
use SomehowDigital\Craft\Lettermint\enums\ApiError;
use SomehowDigital\Craft\Lettermint\enums\TokenStatus;
use SomehowDigital\Craft\Lettermint\models\ApiResult;
use SomehowDigital\Craft\Lettermint\models\TeamApiData;

/**
 * The API of Lettermint, answering with a token status and a team that could not be loaded, and keeping what it is
 * asked for.
 */
final class FakeLettermintApi implements LettermintApiInterface
{
	/** @var list<?string> the project tokens whose status is asked for */
	public array $tokens = [];

	/** @var list<bool> whether the statistics are loaded with each load of the team API */
	public array $loads = [];

	public function __construct(
		private readonly TokenStatus $tokenStatus = TokenStatus::Valid,
	) {}

	public function tokenStatus(#[SensitiveParameter] ?string $token): TokenStatus
	{
		$this->tokens[] = $token;

		return $this->tokenStatus;
	}

	public function teamApi(#[SensitiveParameter] string $token, ?string $projectId = null, bool $withStats = false): TeamApiData
	{
		$this->loads[] = $withStats;

		return new TeamApiData(ApiResult::failed(ApiError::Unavailable), ApiResult::failed(ApiError::Unavailable));
	}
}
