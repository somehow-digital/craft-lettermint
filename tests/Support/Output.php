<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\Tests\Support;

use php_user_filter;

/**
 * Captures what is written to the standard output and error streams, such as the output of a console command, instead
 * of writing it.
 */
final class Output extends php_user_filter
{
	/** @var array<string,string> what was written, by the name of the stream */
	private static array $written = [];

	/**
	 * Runs a callback while capturing the output streams.
	 *
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

		// Craft asks whether the streams are terminals, which a stream with a filter cannot tell.
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
	 * Keeps the data of the stream, and passes none of it on.
	 *
	 * @param resource $in
	 * @param resource $out
	 * @param int $consumed
	 */
	public function filter($in, $out, &$consumed, bool $closing): int
	{
		$stream = is_string($this->params) ? $this->params : 'stdout';

		// The buckets are objects of their own class from PHP 8.4, and of a plain one before.
		while (is_object($bucket = stream_bucket_make_writeable($in))) {
			$data = is_string($bucket->data ?? null) ? $bucket->data : '';
			self::$written[$stream] = (self::$written[$stream] ?? '') . $data;
			$consumed += strlen($data);
		}

		return PSFS_PASS_ON;
	}
}
