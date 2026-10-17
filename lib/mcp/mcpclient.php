<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Mcp;

/**
 * Инструменты MCP-сервера (1.10.0): JSON-RPC 2.0 по Streamable HTTP —
 * tools/list и tools/call с токеном сотрудника. Ответ сервер шлёт JSON или
 * событием text/event-stream (data: {...}) — разбираются оба.
 *
 * 401 — токен не годится: McpException::AUTH (обновить и повторить решает
 * McpConnection). Ошибка инструмента (isError) — не исключение: модель
 * должна увидеть текст ошибки и поправить вызов.
 */
final class McpClient
{
	public const PROTOCOL = '2025-06-18';

	public function __construct(private readonly string $endpoint, private readonly HttpTransportInterface $http)
	{
	}

	/**
	 * Инструменты сервера, открытые сотруднику.
	 *
	 * @return list<array<string, mixed>> сырые описания: name, title, description, inputSchema, annotations
	 * @throws McpException
	 */
	public function listTools(string $token): array
	{
		$tools = [];
		$cursor = null;
		// Страницы — до 10: сервер toolsai-mcp отдаёт всё одной, но протокол разрешает курсор
		for($page = 0; $page < 10; $page++)
		{
			$result = $this->rpc($token, 'tools/list', $cursor !== null ? ['cursor' => $cursor] : new \stdClass());
			foreach(is_array($result['tools'] ?? null) ? $result['tools'] : [] as $tool)
			{
				if(is_array($tool))
				{
					$tools[] = $tool;
				}
			}
			$cursor = is_string($result['nextCursor'] ?? null) && $result['nextCursor'] !== '' ? $result['nextCursor'] : null;
			if($cursor === null)
			{
				break;
			}
		}

		return $tools;
	}

	/**
	 * Вызов инструмента: текст ответа (куски content типа text) и признак ошибки.
	 *
	 * @return array{text: string, isError: bool}
	 * @throws McpException
	 */
	public function callTool(string $token, string $name, array $arguments): array
	{
		$result = $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $arguments === [] ? new \stdClass() : $arguments]);
		$parts = [];
		foreach(is_array($result['content'] ?? null) ? $result['content'] : [] as $item)
		{
			if(is_array($item) && ($item['type'] ?? '') === 'text' && is_string($item['text'] ?? null))
			{
				$parts[] = $item['text'];
			}
		}
		if($parts === [] && isset($result['structuredContent']))
		{
			$parts[] = (string)json_encode($result['structuredContent'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		return ['text' => implode("\n", $parts), 'isError' => ($result['isError'] ?? false) === true];
	}

	/** @throws McpException */
	private function rpc(string $token, string $method, array|\stdClass $params): array
	{
		$response = $this->http->request('POST', $this->endpoint, [
			'Content-Type' => 'application/json',
			'Accept' => 'application/json, text/event-stream',
			'Authorization' => 'Bearer '.$token,
			'MCP-Protocol-Version' => self::PROTOCOL,
		], (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

		if($response['status'] === 0)
		{
			throw new McpException('MCP-сервер не отвечает — попробуйте позже', McpException::NETWORK);
		}
		if($response['status'] === 401)
		{
			throw new McpException('Подключение к MCP устарело — подключитесь заново', McpException::AUTH);
		}
		$message = static::parseBody($response['body']);
		if($message === null)
		{
			throw new McpException('MCP-сервер ответил ошибкой ('.$response['status'].')', McpException::SERVER);
		}
		if(is_array($message['error'] ?? null))
		{
			$text = is_string($message['error']['message'] ?? null) ? mb_substr($message['error']['message'], 0, 300) : 'ошибка';
			throw new McpException('MCP-сервер отказал: '.$text, McpException::TOOL);
		}

		return is_array($message['result'] ?? null) ? $message['result'] : [];
	}

	/**
	 * Тело ответа JSON-RPC: JSON или поток событий (последнее data: с объектом).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function parseBody(string $body): ?array
	{
		$body = trim($body);
		$direct = json_decode($body, true);
		if(is_array($direct))
		{
			return $direct;
		}
		$found = null;
		foreach(preg_split('/\r?\n/', $body) ?: [] as $line)
		{
			if(str_starts_with($line, 'data:'))
			{
				$data = json_decode(trim(substr($line, 5)), true);
				if(is_array($data) && (isset($data['result']) || isset($data['error'])))
				{
					$found = $data;
				}
			}
		}

		return $found;
	}
}
