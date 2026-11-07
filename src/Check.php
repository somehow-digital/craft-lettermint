<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Lettermint;

use Craft;
use JsonSerializable;

/**
 * A check of the health report or the utility, with an ID that stays the same across languages, e.g. for monitoring.
 *
 * The message is kept untranslated and translated only when the text is read, as checks are cached for all users,
 * whatever their language.
 */
final readonly class Check implements JsonSerializable
{
	public function __construct(
		public string $id,
		public CheckStatus $status,
		public string $message,
		/** @var array<string,mixed> */
		public array $params = [],
	) {}

	/**
	 * @param array<string,mixed> $params
	 */
	public static function ok(string $id, string $message, array $params = []): self
	{
		return new self($id, CheckStatus::Ok, $message, $params);
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public static function warning(string $id, string $message, array $params = []): self
	{
		return new self($id, CheckStatus::Warning, $message, $params);
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public static function error(string $id, string $message, array $params = []): self
	{
		return new self($id, CheckStatus::Error, $message, $params);
	}

	/**
	 * The worst status of the checks, or `ok` without any.
	 *
	 * @param list<self> $checks
	 */
	public static function worst(array $checks): CheckStatus
	{
		return CheckStatus::worst(...array_map(static fn(self $check): CheckStatus => $check->status, $checks));
	}

	/**
	 * The message, translated to the current language.
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
