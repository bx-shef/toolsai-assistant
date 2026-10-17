<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Mcp;

/**
 * Подключение сотрудника к MCP-серверу toolsai-mcp (1.9.0).
 *
 *   start()    — адрес входа: регистрация чата на сервере (раз в 25 дней —
 *                сервер хранит клиентов 30), PKCE и state в сессии;
 *   finish()   — колбэк: state из сессии, код → токены, запись сотруднику;
 *   status()   — подключён ли сотрудник к серверу из настроек;
 *   tools() / call() — инструменты с токеном сотрудника: истёк — обновить;
 *                сервер ответил 401 — обновить и повторить раз; обновить
 *                не вышло — подключение удаляется, McpException::AUTH.
 *
 * Кто вызывает — только $userId текущего сотрудника (контроллер), сервер MCP
 * сам проверяет права в Битрикс24 и свои списки доступа. Чистый класс с
 * подменяемыми хранилищами и HTTP (mcp_test.php).
 */
final class McpConnection implements \Shef\ToolsAiAssistant\Agent\McpGatewayInterface
{
	/** Регистрация чата на сервере живёт 30 дней — обновляем заранее. */
	public const CLIENT_TTL = 25 * 86400;
	/** Вход нужно закончить за 15 минут. */
	public const LOGIN_TTL = 900;
	/** Токен обновляем за минуту до конца. */
	public const SKEW = 60;
	public const CLIENT_NAME = 'Чат ИИ-помощника (shef.toolsaiassistant)';

	private readonly OAuthClient $oauth;
	private ?array $meta = null;

	public function __construct(
		private readonly string $server,
		private readonly HttpTransportInterface $http,
		private readonly TokenStoreInterface $tokens,
		private readonly StateStoreInterface $session,
		private readonly StateStoreInterface $portal,
		private readonly ?\Closure $clock = null,
	)
	{
		$this->oauth = new OAuthClient($server, $http);
	}

	/** Подключён ли сотрудник к серверу из настроек. */
	public function status(int $userId): bool
	{
		$token = $this->tokens->get($userId);

		return $token !== null && $token['server'] === $this->server;
	}

	/**
	 * Начать вход: адрес, который окно чата откроет в новом окне.
	 *
	 * @param string $redirectUri страница возврата на этом портале (https)
	 * @param string $portalHost хост портала — подсказка экрану согласия
	 * @throws McpException
	 */
	public function start(int $userId, string $redirectUri, string $portalHost): string
	{
		$meta = $this->metadata();
		$clientId = $this->clientId($meta, $redirectUri);
		$pkce = OAuthClient::pkce();
		$state = bin2hex(random_bytes(16));
		$this->session->set('login', [
			'state' => $state, 'verifier' => $pkce['verifier'], 'clientId' => $clientId,
			'redirect' => $redirectUri, 'server' => $this->server, 'userId' => $userId, 'at' => $this->now(),
		]);

		return OAuthClient::authorizeUrl($meta['authorization_endpoint'], $clientId, $redirectUri, $pkce['challenge'], $state, $portalHost);
	}

	/**
	 * Колбэк: обменять код на токены и запомнить сотруднику.
	 *
	 * @param array<string, mixed> $query параметры страницы возврата: code, state или error
	 * @throws McpException
	 */
	public function finish(int $userId, array $query): void
	{
		$login = $this->session->get('login');
		$this->session->set('login', null);
		$state = is_string($query['state'] ?? null) ? $query['state'] : '';
		if($login === null || $state === '' || !hash_equals((string)$login['state'], $state)
			|| (int)$login['userId'] !== $userId || (string)$login['server'] !== $this->server
			|| $this->now() - (int)$login['at'] > self::LOGIN_TTL)
		{
			throw new McpException('Вход устарел или начат в другом окне — нажмите «Подключить» в чате ещё раз', McpException::AUTH);
		}
		if(is_string($query['error'] ?? null))
		{
			throw new McpException($query['error'] === 'access_denied' ? 'Подключение отменено или MCP вам не открыт — обратитесь к администратору' : 'MCP-сервер отказал во входе', McpException::AUTH);
		}
		$code = is_string($query['code'] ?? null) ? $query['code'] : '';
		if($code === '')
		{
			throw new McpException('MCP-сервер не вернул код входа', McpException::AUTH);
		}
		$meta = $this->metadata();
		$token = $this->oauth->exchange($meta['token_endpoint'], (string)$login['clientId'], (string)$login['redirect'], $code, (string)$login['verifier'], $this->now());
		$this->tokens->save($userId, ['server' => $this->server, 'clientId' => (string)$login['clientId']] + $token);
	}

	/** Отключиться: забыть токены сотрудника. */
	public function disconnect(int $userId): void
	{
		$this->tokens->delete($userId);
	}

	/**
	 * Инструменты, открытые сотруднику.
	 *
	 * @return list<array<string, mixed>>
	 * @throws McpException
	 */
	public function tools(int $userId): array
	{
		return $this->withToken($userId, fn(McpClient $client, string $token): array => $client->listTools($token));
	}

	/**
	 * Вызвать инструмент от имени сотрудника.
	 *
	 * @return array{text: string, isError: bool}
	 * @throws McpException
	 */
	public function call(int $userId, string $name, array $arguments): array
	{
		return $this->withToken($userId, fn(McpClient $client, string $token): array => $client->callTool($token, $name, $arguments));
	}

	/** @throws McpException */
	private function withToken(int $userId, \Closure $action): mixed
	{
		$token = $this->tokens->get($userId);
		if($token === null || $token['server'] !== $this->server)
		{
			throw new McpException('Подключитесь к MCP-серверу кнопкой «Подключить» в чате', McpException::AUTH);
		}
		$client = new McpClient($this->oauth->mcpEndpoint(), $this->http);
		if($token['expiresAt'] - self::SKEW <= $this->now())
		{
			$token = $this->refresh($userId, $token);
		}
		try
		{
			return $action($client, $token['access']);
		}
		catch(McpException $exception)
		{
			if($exception->errorCode !== McpException::AUTH)
			{
				throw $exception;
			}
		}
		// 401: токен отозвали раньше срока — обновить и повторить раз
		return $action($client, $this->refresh($userId, $token)['access']);
	}

	/** @throws McpException AUTH — подключение удалено */
	private function refresh(int $userId, array $token): array
	{
		try
		{
			$fresh = $this->oauth->refresh($this->metadata()['token_endpoint'], $token['clientId'], $token['refresh'], $this->now());
		}
		catch(McpException $exception)
		{
			if($exception->errorCode === McpException::AUTH)
			{
				$this->tokens->delete($userId);
			}
			throw $exception;
		}
		// Сервер не прислал новый refresh — остаётся прежний
		$token = ['server' => $this->server, 'clientId' => $token['clientId'], 'access' => $fresh['access'],
			'refresh' => $fresh['refresh'] !== '' ? $fresh['refresh'] : $token['refresh'], 'expiresAt' => $fresh['expiresAt']];
		$this->tokens->save($userId, $token);

		return $token;
	}

	/** Регистрация чата: своя на сервер и адрес возврата, обновляется заранее. */
	private function clientId(array $meta, string $redirectUri): string
	{
		$saved = $this->portal->get('client');
		if($saved !== null && ($saved['server'] ?? '') === $this->server && ($saved['redirect'] ?? '') === $redirectUri
			&& is_string($saved['id'] ?? null) && $this->now() - (int)($saved['at'] ?? 0) < self::CLIENT_TTL)
		{
			return $saved['id'];
		}
		$id = $this->oauth->register($meta['registration_endpoint'], $redirectUri, self::CLIENT_NAME);
		$this->portal->set('client', ['server' => $this->server, 'redirect' => $redirectUri, 'id' => $id, 'at' => $this->now()]);

		return $id;
	}

	private function metadata(): array
	{
		return $this->meta ??= $this->oauth->metadata();
	}

	private function now(): int
	{
		return $this->clock !== null ? ($this->clock)() : time();
	}
}
