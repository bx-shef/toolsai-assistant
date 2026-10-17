<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Отказ чата, который можно показать сотруднику как есть: текст без ключей,
 * адресов и тел ответов модели. Код — для контроллера и тестов.
 */
final class ChatException extends \RuntimeException
{
	public const ACCESS = 'access';
	public const ROLE = 'role';
	public const NO_ROLES = 'no_roles';
	public const EMPTY_QUESTION = 'empty_question';
	public const QUOTA = 'quota';
	public const RATE = 'rate';
	public const RECORD = 'record';
	public const MODEL = 'model';
	public const FEEDBACK = 'feedback';
	/** Режим «Агент»: ждущее действие устарело, инструмент пропал (1.9.0). Отказы MCP — коды Mcp\McpException (mcp_auth …). */
	public const AGENT = 'agent';

	/** Текст — для сотрудника; $errorCode — константа класса (уходит кодом ошибки ajax). */
	public function __construct(string $message, public readonly string $errorCode, ?\Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
	}
}
