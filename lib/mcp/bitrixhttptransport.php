<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Mcp;

use Bitrix\Main\Web\HttpClient;

/**
 * HTTP ядра (Bitrix\Main\Web\HttpClient) для MCP-сервера: без редиректов,
 * со своим таймаутом. Приватные адреса разрешены: сервер часто стоит рядом с
 * порталом (BitrixVM, 127.0.0.1 за nginx), а адрес задаёт администратор.
 */
final class BitrixHttpTransport implements HttpTransportInterface
{
	public function __construct(private readonly int $timeout = 60)
	{
	}

	public function request(string $method, string $url, array $headers = [], string $body = ''): array
	{
		$client = new HttpClient([
			'redirect' => false,
			'socketTimeout' => 10,
			'streamTimeout' => $this->timeout,
			'privateIp' => true,
		]);
		foreach($headers as $name => $value)
		{
			$client->setHeader($name, $value);
		}
		$ok = $client->query($method, $url, $method === 'GET' ? null : $body);
		$result = $ok !== false ? (string)$client->getResult() : '';
		$out = [];
		foreach($client->getHeaders()->toArray() as $header)
		{
			$name = strtolower((string)($header['name'] ?? ''));
			$values = (array)($header['values'] ?? []);
			if($name !== '' && $values !== [])
			{
				$out[$name] = (string)end($values);
			}
		}

		return ['status' => $ok !== false ? (int)$client->getStatus() : 0, 'headers' => $out, 'body' => $result];
	}
}
