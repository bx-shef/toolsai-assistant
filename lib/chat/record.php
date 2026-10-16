<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Запись CRM рядом с чатом: тип и ID. Только положительные числа — иначе
 * записи нет (fromArray() вернёт null).
 *
 * Чистый класс — без ядра.
 */
final class Record
{
	public function __construct(
		public readonly int $entityTypeId,
		public readonly int $id,
	)
	{
	}

	/** Из запроса браузера: {entityTypeId, id}; мусор — null. */
	public static function fromArray(mixed $raw): ?self
	{
		if(!is_array($raw))
		{
			return null;
		}
		$type = static::positive($raw['entityTypeId'] ?? null);
		$id = static::positive($raw['id'] ?? null);

		return $type > 0 && $id > 0 ? new self($type, $id) : null;
	}

	/** Целое больше нуля из числа или строки цифр, иначе 0. */
	private static function positive(mixed $value): int
	{
		if(is_int($value))
		{
			return max(0, $value);
		}

		return is_string($value) && preg_match('/^[1-9]\d{0,9}$/', $value) ? (int)$value : 0;
	}
}
