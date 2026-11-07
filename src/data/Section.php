<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\data;

use SomehowDigital\Craft\Lettermint\SectionError;

/**
 * A section of the team API, which holds either its data or the error it could not be loaded with.
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
		public ?SectionError $error,
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
	public static function failed(SectionError $error): self
	{
		return new self(null, $error);
	}
}
