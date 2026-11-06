<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;

/**
 * A check of the health report or the utility, with an ID that stays the same across languages, e.g. for monitoring.
 *
 * @internal
 */
final class Check
{
	public const OK = 'ok';
	public const WARNING = 'warning';
	public const ERROR = 'error';

	/**
	 * How bad each status is, to find the overall status.
	 */
	private const SEVERITY = [self::OK => 0, self::WARNING => 1, self::ERROR => 2];

	/**
	 * @param array<string,mixed> $params
	 * @return array{id:string,status:string,text:string}
	 */
	public static function create(string $id, string $status, string $message, array $params = []): array
	{
		return ['id' => $id, 'status' => $status, 'text' => Craft::t('lettermint', $message, $params)];
	}

	/**
	 * The worst status of the checks, or `ok` without any.
	 *
	 * @param list<array{status:string,...}> $checks
	 */
	public static function worst(array $checks): string
	{
		$status = self::OK;

		foreach ($checks as $check) {
			if (self::SEVERITY[$check['status']] > self::SEVERITY[$status]) {
				$status = $check['status'];
			}
		}

		return $status;
	}
}
