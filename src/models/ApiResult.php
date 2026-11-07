<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use SomehowDigital\Craft\Lettermint\enums\ApiError;

/**
 * A result of the team API, which holds either its data or the error it could not be loaded with.
 *
 * @template-covariant T
 * @internal
 */
final readonly class ApiResult
{
	/**
	 * @param T|null $data
	 */
	private function __construct(
		public mixed $data,
		public ?ApiError $error,
	) {}

	/**
	 * @template D
	 * @param D $data
	 * @return self<D>
	 */
	public static function loaded(mixed $data): self
	{
		return new self($data, null);
	}

	/**
	 * @return self<never>
	 */
	public static function failed(ApiError $error): self
	{
		return new self(null, $error);
	}
}
