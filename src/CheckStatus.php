<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

/**
 * The status of a check, or of the health report as the worst status of its checks.
 */
enum CheckStatus: string
{
	/**
	 * The check passed.
	 */
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
	 * The worst of the statuses, or `ok` without any.
	 */
	public static function worst(self ...$statuses): self
	{
		$worst = self::Ok;

		foreach ($statuses as $status) {
			// A status as bad as the worst so far is the same status.
			if ($status->severity() > $worst->severity()) { // @pest-mutate-ignore: GreaterToGreaterOrEqual
				$worst = $status;
			}
		}

		return $worst;
	}

	/**
	 * How bad the status is, to compare statuses.
	 *
	 * Only the order of the values matters, so lowering the lowest or raising the highest changes nothing.
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
