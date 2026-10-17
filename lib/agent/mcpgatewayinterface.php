<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Agent;

/**
 * Инструменты MCP от имени сотрудника — Mcp\McpConnection на портале,
 * подмена в тестах. Отказы — Mcp\McpException.
 */
interface McpGatewayInterface
{
	/** @return list<array<string, mixed>> */
	public function tools(int $userId): array;

	/** @return array{text: string, isError: bool} */
	public function call(int $userId, string $name, array $arguments): array;
}
