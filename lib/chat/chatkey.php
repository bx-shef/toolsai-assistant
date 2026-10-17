<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Ключ чата: «general» — общий чат, «record:<entityTypeId>:<id>» — чат по
 * записи CRM, «docs» — чат справки (1.3.0: окно открыто из справки
 * shef.toolsai, open({context: 'docs'}); выбор роли там не трогает общий чат). По нему запоминается выбранная роль (Model\RoleChoiceTable),
 * а в браузере — переписка.
 *
 * Чистый класс — без ядра (chat_test.php).
 */
final class ChatKey
{
	public const GENERAL = 'general';
	public const DOCS = 'docs';
	/** Контекст окна из браузера, у которого свой ключ. */
	public const CONTEXT_DOCS = 'docs';
	public const MAX_LENGTH = 64;

	/**
	 * Ключ по записи; записи нет — общий или справки.
	 *
	 * @param string $context контекст окна: «docs» — справка (только без записи)
	 */
	public static function of(?Record $record, string $context = ''): string
	{
		if($record !== null)
		{
			return 'record:'.$record->entityTypeId.':'.$record->id;
		}

		return $context === self::CONTEXT_DOCS ? self::DOCS : self::GENERAL;
	}

	/** Контекст окна из запроса: только известный, иначе пусто. */
	public static function context(mixed $raw): string
	{
		return $raw === self::CONTEXT_DOCS ? self::CONTEXT_DOCS : '';
	}
}
