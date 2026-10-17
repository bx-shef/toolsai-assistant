<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Mcp;

/**
 * Небольшое состояние подключения: начатый вход (сессия сотрудника) и
 * регистрация чата на MCP-сервере (опция модуля). В тестах — массив.
 */
interface StateStoreInterface
{
	public function get(string $key): ?array;

	/** null — удалить. */
	public function set(string $key, ?array $value): void;
}
