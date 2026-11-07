<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\data;

/**
 * A section of the team API, which holds either its data or an error, one of the `ERROR_*` constants of `TeamApi`.
 *
 * @template-covariant T
 */
final readonly class Section
{
	/**
	 * @param T|null $data
	 */
	private function __construct(
		public mixed $data,
		public ?string $error,
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
	public static function failed(string $error): self
	{
		return new self(null, $error);
	}
}
