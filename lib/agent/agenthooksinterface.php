<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Agent;

/**
 * Участие других модулей в работе агента (1.10.0) — события модуля
 * (AgentEvents на портале, подмена в тестах; контракт — docs/guide/04-events.md):
 *
 *   OnAgentToolsCollect     — правила для списка инструментов (ToolCatalog);
 *   OnBeforeAgentToolCall   — запретить вызов (текст причины уйдёт модели и
 *                             сотруднику);
 *   OnAfterAgentToolCall    — узнать о вызове: журнал, уведомления.
 */
interface AgentHooksInterface
{
	/**
	 * @param list<string> $names имена инструментов сервера
	 * @return list<array<string, mixed>> правила модулей для ToolCatalog::build()
	 */
	public function toolRules(int $userId, array $names): array;

	/** Причина запрета или null — можно. */
	public function beforeCall(int $userId, array $tool, array $arguments): ?string;

	public function afterCall(int $userId, array $tool, array $arguments, bool $ok): void;
}
