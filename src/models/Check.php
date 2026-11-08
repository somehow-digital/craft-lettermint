<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint\models;

use Craft;
use JsonSerializable;
use SomehowDigital\Craft\Lettermint\enums\CheckStatus;
use SomehowDigital\Craft\Lettermint\enums\CheckType;

/**
 * A health check with a language-independent ID for monitoring.
 *
 * The message is translated on read, as checks are cached across users and languages.
 *
 * @internal
 */
final readonly class Check implements JsonSerializable
{
	/**
	 * The type, plus the subject if any, e.g. `route.blog`.
	 */
	public string $id;

	/**
	 * @param array<string,mixed> $params
	 * @param string|null $subject E.g. a site handle.
	 */
	public function __construct(
		public CheckType $type,
		public CheckStatus $status,
		public string $message,
		public array $params = [],
		public ?string $subject = null,
	) {
		$this->id = $subject !== null ? "{$type->value}.{$subject}" : $type->value;
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public static function ok(CheckType $type, string $message, array $params = [], ?string $subject = null): self
	{
		return new self($type, CheckStatus::Ok, $message, $params, $subject);
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public static function warning(CheckType $type, string $message, array $params = [], ?string $subject = null): self
	{
		return new self($type, CheckStatus::Warning, $message, $params, $subject);
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public static function error(CheckType $type, string $message, array $params = [], ?string $subject = null): self
	{
		return new self($type, CheckStatus::Error, $message, $params, $subject);
	}

	/**
	 * @param list<self> $checks
	 */
	public static function worst(array $checks): CheckStatus
	{
		return CheckStatus::worst(...array_map(static fn(self $check): CheckStatus => $check->status, $checks));
	}

	/**
	 * The translated message.
	 */
	public function text(): string
	{
		return Craft::t('lettermint', $this->message, $this->params);
	}

	/**
	 * @return array{id:string,status:string,text:string}
	 */
	public function jsonSerialize(): array
	{
		return ['id' => $this->id, 'status' => $this->status->value, 'text' => $this->text()];
	}
}
