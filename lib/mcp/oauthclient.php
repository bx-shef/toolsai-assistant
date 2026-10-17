<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Mcp;

/**
 * Чат как клиент OAuth 2.1 MCP-сервера toolsai-mcp (1.9.0) — так же, как
 * Claude или Cursor: метаданные сервера (RFC 8414), динамическая регистрация
 * (RFC 7591), вход с PKCE S256, refresh. Адреса берутся из метаданных, а не
 * собираются руками: сервер может стоять под префиксом (https://портал/mcp-ai).
 *
 * Сотрудник входит в Битрикс24 сам, на экране согласия сервера; портал
 * подставляется подсказкой portal= (toolsai-mcp, docs/ARCHITECTURE.md).
 * Ответы сервера наружу не отдаются — в них бывают токены.
 *
 * HTTP — через HttpTransportInterface (тест mcp_test.php без сети).
 */
final class OAuthClient
{
	public function __construct(private readonly string $baseUrl, private readonly HttpTransportInterface $http)
	{
	}

	/** Адрес инструментов: <база>/mcp. */
	public function mcpEndpoint(): string
	{
		return $this->baseUrl.'/mcp';
	}

	/**
	 * Метаданные сервера авторизации: authorization_endpoint, token_endpoint,
	 * registration_endpoint. Сервер под префиксом отдаёт их и в самом issuer.
	 *
	 * @return array{authorization_endpoint: string, token_endpoint: string, registration_endpoint: string}
	 * @throws McpException
	 */
	public function metadata(): array
	{
		$response = $this->http->request('GET', $this->baseUrl.'/.well-known/oauth-authorization-server', ['Accept' => 'application/json']);
		$data = static::json($response);
		$out = [];
		foreach(['authorization_endpoint', 'token_endpoint', 'registration_endpoint'] as $key)
		{
			$url = is_string($data[$key] ?? null) ? $data[$key] : '';
			// Адреса — того же сервера: чужой хост в метаданных — не доверяем
			if(!static::sameHost($url, $this->baseUrl))
			{
				throw new McpException('MCP-сервер отдал неверные адреса входа — проверьте адрес в настройках модуля', McpException::CONFIG);
			}
			$out[$key] = $url;
		}

		return $out;
	}

	/**
	 * Регистрация клиента (RFC 7591): публичный, без секрета.
	 *
	 * @throws McpException
	 */
	public function register(string $registrationEndpoint, string $redirectUri, string $name): string
	{
		$response = $this->http->request('POST', $registrationEndpoint, ['Content-Type' => 'application/json', 'Accept' => 'application/json'], (string)json_encode([
			'client_name' => $name,
			'redirect_uris' => [$redirectUri],
			'grant_types' => ['authorization_code', 'refresh_token'],
			'response_types' => ['code'],
			'token_endpoint_auth_method' => 'none',
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		$data = static::json($response);
		$id = is_string($data['client_id'] ?? null) ? $data['client_id'] : '';
		if($id === '')
		{
			throw new McpException('MCP-сервер не зарегистрировал чат — попробуйте позже', McpException::SERVER);
		}

		return $id;
	}

	/**
	 * PKCE: verifier и challenge S256 (RFC 7636).
	 *
	 * @return array{verifier: string, challenge: string}
	 */
	public static function pkce(?string $verifier = null): array
	{
		$verifier ??= static::b64url(random_bytes(32));

		return ['verifier' => $verifier, 'challenge' => static::b64url(hash('sha256', $verifier, true))];
	}

	/** Адрес входа: сотрудник откроет его в новом окне. */
	public static function authorizeUrl(string $authorizationEndpoint, string $clientId, string $redirectUri, string $challenge, string $state, string $portal): string
	{
		return $authorizationEndpoint.(str_contains($authorizationEndpoint, '?') ? '&' : '?').http_build_query([
			'response_type' => 'code',
			'client_id' => $clientId,
			'redirect_uri' => $redirectUri,
			'code_challenge' => $challenge,
			'code_challenge_method' => 'S256',
			'state' => $state,
			'portal' => $portal,
		], '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Код → токены.
	 *
	 * @return array{access: string, refresh: string, expiresAt: int}
	 * @throws McpException
	 */
	public function exchange(string $tokenEndpoint, string $clientId, string $redirectUri, string $code, string $verifier, int $now): array
	{
		return $this->token($tokenEndpoint, [
			'grant_type' => 'authorization_code',
			'client_id' => $clientId,
			'redirect_uri' => $redirectUri,
			'code' => $code,
			'code_verifier' => $verifier,
		], $now);
	}

	/**
	 * Обновить токены; refresh одноразовый — сохранить новый.
	 *
	 * @return array{access: string, refresh: string, expiresAt: int}
	 * @throws McpException AUTH — вход нужен заново
	 */
	public function refresh(string $tokenEndpoint, string $clientId, string $refreshToken, int $now): array
	{
		return $this->token($tokenEndpoint, ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $refreshToken], $now);
	}

	/** @return array{access: string, refresh: string, expiresAt: int} */
	private function token(string $endpoint, array $params, int $now): array
	{
		$response = $this->http->request('POST', $endpoint, ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], http_build_query($params, '', '&'));
		if($response['status'] >= 400 && $response['status'] < 500)
		{
			throw new McpException('Подключение к MCP устарело — подключитесь заново', McpException::AUTH);
		}
		$data = static::json($response);
		$access = is_string($data['access_token'] ?? null) ? $data['access_token'] : '';
		if($access === '')
		{
			throw new McpException('MCP-сервер не выдал доступ — подключитесь заново', McpException::AUTH);
		}
		$ttl = is_int($data['expires_in'] ?? null) && $data['expires_in'] > 0 ? $data['expires_in'] : 3600;

		return ['access' => $access, 'refresh' => is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : '', 'expiresAt' => $now + $ttl];
	}

	/** Тело ответа как JSON-объект; сеть или не JSON — McpException. */
	private static function json(array $response): array
	{
		if($response['status'] === 0)
		{
			throw new McpException('MCP-сервер не отвечает — проверьте адрес в настройках модуля или попробуйте позже', McpException::NETWORK);
		}
		$data = json_decode($response['body'], true);
		if($response['status'] >= 400 || !is_array($data))
		{
			throw new McpException('MCP-сервер ответил ошибкой ('.$response['status'].')', McpException::SERVER);
		}

		return $data;
	}

	/** Адрес https того же хоста, что и база. */
	public static function sameHost(string $url, string $base): bool
	{
		$u = parse_url($url);
		$b = parse_url($base);

		return is_array($u) && is_array($b) && ($u['scheme'] ?? '') === 'https'
			&& strtolower((string)($u['host'] ?? '')) === strtolower((string)($b['host'] ?? ''))
			&& (int)($u['port'] ?? 443) === (int)($b['port'] ?? 443);
	}

	private static function b64url(string $bytes): string
	{
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}
}
