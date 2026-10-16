<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Ключ чата: «general» — общий чат, «record:<entityTypeId>:<id>» — чат по
 * записи CRM. По нему запоминается выбранная роль (Model\RoleChoiceTable),
 * а в браузере — переписка.
 *
 * Чистый класс — без ядра (chat_test.php).
 */
final class ChatKey
{
	public const GENERAL = 'general';
	public const MAX_LENGTH = 64;

	/** Ключ по записи; записи нет — общий. */
	public static function of(?Record $record): string
	{
		return $record === null ? self::GENERAL : 'record:'.$record->entityTypeId.':'.$record->id;
	}
}
