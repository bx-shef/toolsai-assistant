<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Mcp;

/**
 * HTTP до MCP-сервера (1.10.0). На портале — BitrixHttpTransport (HttpClient ядра),
 * в тестах — подмена: клиенты MCP и OAuth проверяются без сети.
 */
interface HttpTransportInterface
{
	/**
	 * Запрос; редиректы не проходятся (адрес сервера задаёт администратор, а
	 * редирект увёл бы токен сотрудника на чужой адрес).
	 *
	 * @param array<string, string> $headers
	 * @return array{status: int, headers: array<string, string>, body: string} status 0 — сеть не ответила
	 */
	public function request(string $method, string $url, array $headers = [], string $body = ''): array;
}
