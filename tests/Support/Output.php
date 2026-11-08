<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\Support;

use php_user_filter;

/**
 * Captures STDOUT and STDERR instead of writing them.
 */
final class Output extends php_user_filter
{
	/** @var array<string,string> by stream name */
	private static array $written = [];

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return array{0:T,1:string,2:string} The result, and what was written to the standard output and error streams.
	 */
	public static function capture(callable $callback): array
	{
		if (!in_array('lettermint.output', stream_get_filters(), true)) {
			stream_filter_register('lettermint.output', self::class);
		}

		self::$written = ['stdout' => '', 'stderr' => ''];
		$stdout = stream_filter_append(STDOUT, 'lettermint.output', STREAM_FILTER_WRITE, 'stdout');
		$stderr = stream_filter_append(STDERR, 'lettermint.output', STREAM_FILTER_WRITE, 'stderr');

		// Filtered streams can't tell Craft whether they're terminals.
		$previous = set_error_handler(null);
		restore_error_handler();
		set_error_handler(static fn(int $level, string $message, string $file = '', int $line = 0): bool => str_starts_with($message, 'posix_isatty(): Could not use stream')
			|| ($previous !== null && (bool) $previous($level, $message, $file, $line)), E_WARNING);

		try {
			$result = $callback();
		} finally {
			restore_error_handler();

			if ($stdout !== false) {
				stream_filter_remove($stdout);
			}

			if ($stderr !== false) {
				stream_filter_remove($stderr);
			}
		}

		return [$result, self::$written['stdout'], self::$written['stderr']];
	}

	/**
	 * Swallows the stream's data.
	 *
	 * @param resource $in
	 * @param resource $out
	 * @param int $consumed
	 */
	public function filter($in, $out, &$consumed, bool $closing): int
	{
		$stream = is_string($this->params) ? $this->params : 'stdout';

		// Buckets are `StreamBucket` objects from PHP 8.4, `stdClass` before.
		while (is_object($bucket = stream_bucket_make_writeable($in))) {
			$data = is_string($bucket->data ?? null) ? $bucket->data : '';
			self::$written[$stream] = (self::$written[$stream] ?? '') . $data;
			$consumed += strlen($data);
		}

		return PSFS_PASS_ON;
	}
}
