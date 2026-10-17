<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Mcp;

/**
 * Подключение сотрудника к MCP-серверу. На портале — TokenStore (таблица,
 * токены зашифрованы), в тестах — массив.
 *
 * Запись: server, clientId, access, refresh, expiresAt (unix).
 */
interface TokenStoreInterface
{
	/** @return array{server: string, clientId: string, access: string, refresh: string, expiresAt: int}|null */
	public function get(int $userId): ?array;

	/** @param array{server: string, clientId: string, access: string, refresh: string, expiresAt: int} $token */
	public function save(int $userId, array $token): void;

	public function delete(int $userId): void;

	/**
	 * Выполнить под блокировкой сотрудника: обновление токена — одно на
	 * сотрудника (сервер вращает refresh, второй параллельный обмен получил
	 * бы отказ и стёр подключение).
	 */
	public function locked(int $userId, \Closure $action): mixed;
}
