<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\enums;

/**
 * @internal
 */
enum CheckStatus: string
{
	case Ok = 'ok';

	/**
	 * Emails are still sent, but something needs attention.
	 */
	case Warning = 'warning';

	/**
	 * Emails are not sent, or not as configured.
	 */
	case Error = 'error';

	/**
	 * The worst status, or `ok` for none.
	 */
	public static function worst(self ...$statuses): self
	{
		$worst = self::Ok;

		foreach ($statuses as $status) {
			// Equal severity means the same status.
			if ($status->severity() > $worst->severity()) { // @pest-mutate-ignore: GreaterToGreaterOrEqual
				$worst = $status;
			}
		}

		return $worst;
	}

	/**
	 * Only the order of the values matters.
	 */
	public function severity(): int
	{
		return match ($this) {
			self::Ok => 0, // @pest-mutate-ignore: DecrementInteger
			self::Warning => 1,
			self::Error => 2, // @pest-mutate-ignore: IncrementInteger
		};
	}
}
