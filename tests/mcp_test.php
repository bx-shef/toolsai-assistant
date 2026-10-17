<?php declare(strict_types=1);

/**
 * Клиент MCP-сервера toolsai-mcp (1.10.0) на подменённом HTTP: метаданные
 * OAuth и чужие адреса в них, регистрация, вход с PKCE и state, обмен кода,
 * токены (шифрование), обновление по сроку и по 401, отзыв — подключение
 * удаляется; JSON-RPC: JSON и поток событий, ошибка инструмента — не
 * исключение.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Mcp\HttpTransportInterface;
use Shef\ToolsAiAssistant\Mcp\McpClient;
use Shef\ToolsAiAssistant\Mcp\McpConnection;
use Shef\ToolsAiAssistant\Mcp\McpException;
use Shef\ToolsAiAssistant\Mcp\OAuthClient;
use Shef\ToolsAiAssistant\Mcp\StateStoreInterface;
use Shef\ToolsAiAssistant\Mcp\TokenCrypto;
use Shef\ToolsAiAssistant\Mcp\TokenStoreInterface;

/** Подставной MCP-сервер: отвечает по адресу и методу. */
final class FakeServer implements HttpTransportInterface
{
	public array $requests = [];
	public string $base = 'https://box.example.by/mcp-ai';
	public array $meta;
	public bool $sse = true;
	/** access => true: годен. */
	public array $valid = ['a1' => true];
	public array $refreshes = ['r1' => ['a2', 'r2']];
	public bool $down = false;

	public function __construct()
	{
		$this->meta = [
			'authorization_endpoint' => $this->base.'/oauth/authorize',
			'token_endpoint' => $this->base.'/oauth/token',
			'registration_endpoint' => $this->base.'/oauth/register',
		];
	}

	public function request(string $method, string $url, array $headers = [], string $body = ''): array
	{
		$this->requests[] = [$method, $url, $headers, $body];
		if($this->down)
		{
			return ['status' => 0, 'headers' => [], 'body' => ''];
		}
		$json = static fn(int $status, array $data): array => ['status' => $status, 'headers' => [], 'body' => json_encode($data, JSON_UNESCAPED_UNICODE)];
		if($url === $this->base.'/.well-known/oauth-authorization-server')
		{
			return $json(200, $this->meta);
		}
		if($url === $this->base.'/oauth/register')
		{
			return $json(201, ['client_id' => 'cli'.count($this->requests)]);
		}
		if($url === $this->base.'/oauth/token')
		{
			parse_str($body, $p);
			if($p['grant_type'] === 'authorization_code')
			{
				return $p['code'] === 'good' && $p['code_verifier'] !== '' ? $json(200, ['access_token' => 'a1', 'refresh_token' => 'r1', 'expires_in' => 3600]) : $json(400, ['error' => 'invalid_grant']);
			}
			$next = $this->refreshes[$p['refresh_token']] ?? null;
			unset($this->refreshes[$p['refresh_token']]);
			if($next === null)
			{
				return $json(400, ['error' => 'invalid_grant']);
			}
			$this->valid[$next[0]] = true;

			return $json(200, ['access_token' => $next[0], 'refresh_token' => $next[1], 'expires_in' => 3600]);
		}
		if($url === $this->base.'/mcp')
		{
			$token = substr($headers['Authorization'] ?? '', 7);
			if(!isset($this->valid[$token]))
			{
				return $json(401, ['error' => 'invalid_token']);
			}
			$rpc = json_decode($body, true);
			$result = match($rpc['method']) {
				'tools/list' => ['tools' => [['name' => 'crm_item_get', 'description' => 'Запись', 'annotations' => ['readOnlyHint' => true]]]],
				'tools/call' => $rpc['params']['name'] === 'broken'
					? ['content' => [['type' => 'text', 'text' => 'Не найдено']], 'isError' => true]
					: ['content' => [['type' => 'text', 'text' => 'Сделка 42']]],
			};
			$message = ['jsonrpc' => '2.0', 'id' => 1, 'result' => $result];

			return $this->sse
				? ['status' => 200, 'headers' => [], 'body' => "event: message\ndata: ".json_encode($message, JSON_UNESCAPED_UNICODE)."\n\n"]
				: $json(200, $message);
		}

		return $json(404, []);
	}

	public function urls(): array
	{
		return array_map(static fn(array $r): string => $r[1], $this->requests);
	}
}

final class MemTokens implements TokenStoreInterface
{
	public array $rows = [];
	public function get(int $userId): ?array { return $this->rows[$userId] ?? null; }
	public function save(int $userId, array $token): void { $this->rows[$userId] = $token; }
	public function delete(int $userId): void { unset($this->rows[$userId]); }
	public int $locks = 0;
	public function locked(int $userId, \Closure $action): mixed { $this->locks++; return $action(); }
}

final class MemState implements StateStoreInterface
{
	public array $rows = [];
	public function get(string $key): ?array { return $this->rows[$key] ?? null; }
	public function set(string $key, ?array $value): void { if($value === null) { unset($this->rows[$key]); } else { $this->rows[$key] = $value; } }
}

$server = new FakeServer();
$tokens = new MemTokens();
$session = new MemState();
$portal = new MemState();
$now = 1000;
$connection = new McpConnection($server->base, $server, $tokens, $session, $portal, static function() use (&$now): int { return $now; });
$redirect = 'https://portal.by/shef/toolsaiassistant/mcp/';

Check::group('вход');
$url = $connection->start(7, $redirect, 'portal.by');
parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
Check::same('адрес входа — из метаданных сервера, PKCE S256, портал подсказкой', [strtok($url, '?'), $q['code_challenge_method'], strlen($q['code_challenge']), $q['portal'], $q['redirect_uri'], $q['response_type']], [$server->base.'/oauth/authorize', 'S256', 43, 'portal.by', $redirect, 'code']);
Check::same('challenge — от verifier из сессии', OAuthClient::pkce($session->rows['login']['verifier'])['challenge'], $q['code_challenge']);
Check::same('PKCE по примеру RFC 7636', OAuthClient::pkce('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk')['challenge'], 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
$clientId = $q['client_id'];
$connection->start(7, $redirect, 'portal.by');
Check::same('регистрация чата — одна на сервер и адрес возврата', count(array_filter($server->urls(), static fn($u) => str_ends_with($u, '/oauth/register'))), 1);

Check::throws('чужой state — отказ', McpException::class, fn() => $connection->finish(7, ['code' => 'good', 'state' => 'x']));
Check::same('…а начатый вход цел: чужая ссылка на страницу возврата его не сбрасывает', $session->get('login') !== null, true);
$connection->start(7, $redirect, 'portal.by');
$state = $session->rows['login']['state'];
Check::throws('другой сотрудник с тем же state — отказ', McpException::class, fn() => $connection->finish(8, ['code' => 'good', 'state' => $state]));
$connection->start(7, $redirect, 'portal.by');
$state = $session->rows['login']['state'];
$now += 1000;
Check::throws('вход старше 15 минут — отказ', McpException::class, fn() => $connection->finish(7, ['code' => 'good', 'state' => $state]));
$now = 1000;
$connection->start(7, $redirect, 'portal.by');
$state = $session->rows['login']['state'];
try { $connection->finish(7, ['error' => 'access_denied', 'state' => $state]); } catch(McpException $e) { $denied = $e->getMessage(); }
Check::same('отказ на экране согласия — понятный текст', str_contains($denied ?? '', 'MCP вам не открыт'), true);
$connection->start(7, $redirect, 'portal.by');
$connection->finish(7, ['code' => 'good', 'state' => $session->rows['login']['state']]);
Check::same('код обменян, сотрудник подключён к этому серверу', [$tokens->rows[7]['access'], $tokens->rows[7]['refresh'], $tokens->rows[7]['expiresAt'], $connection->status(7), $connection->status(8)], ['a1', 'r1', 4600, true, false]);
$other = new McpConnection('https://other.example.by', $server, $tokens, $session, $portal);
Check::same('сменили адрес сервера — подключение не считается', $other->status(7), false);

Check::group('инструменты');
Check::same('tools/list — с токеном сотрудника, поток событий разобран', array_column($connection->tools(7), 'name'), ['crm_item_get']);
$last = end($server->requests);
Check::same('Bearer и Accept для Streamable HTTP', [$last[2]['Authorization'], $last[2]['Accept']], ['Bearer a1', 'application/json, text/event-stream']);
$server->sse = false;
Check::same('tools/call — JSON-ответ', $connection->call(7, 'crm_item_get', ['entity' => 'deal', 'id' => 42]), ['text' => 'Сделка 42', 'isError' => false]);
Check::same('ошибка инструмента — не исключение: модель увидит текст', $connection->call(7, 'broken', []), ['text' => 'Не найдено', 'isError' => true]);
Check::same('пустые аргументы — объект {}, не массив', str_contains(end($server->requests)[3], '"arguments":{}'), true);

Check::group('токены');
$now = 4600;
$connection->tools(7);
Check::same('срок вышел — обновлён до вызова, новый refresh сохранён', [$tokens->rows[7]['access'], $tokens->rows[7]['refresh']], ['a2', 'r2']);
unset($server->valid['a2']);
$server->refreshes['r2'] = ['a3', 'r3'];
$connection->tools(7);
Check::same('401 раньше срока — обновить и повторить раз', $tokens->rows[7]['access'], 'a3');
unset($server->valid['a3']);
$stale = $tokens->rows[7];
$tokens->rows[7] = ['refresh' => 'r9', 'access' => 'a9', 'expiresAt' => 99999] + $stale;
$server->valid['a9'] = true;
Check::same('соседний запрос уже обновил — его пара без обмена (refresh вращается)', (fn() => $this->refresh(7, $stale))->call($connection)['access'], 'a9');
Check::same('обновление — под блокировкой сотрудника', $tokens->locks > 0, true);
$tokens->rows[7] = $stale;
Check::throws('refresh отозван — McpException::AUTH', McpException::class, fn() => $connection->tools(7));
Check::same('…и подключение удалено: окно предложит подключиться заново', isset($tokens->rows[7]), false);
Check::throws('не подключён — AUTH', McpException::class, fn() => $connection->call(9, 'crm_item_get', []));
$server->down = true;
try { (new McpConnection($server->base, $server, $tokens, $session, $portal))->start(7, $redirect, 'portal.by'); } catch(McpException $e) { $code = $e->errorCode; }
Check::same('сервер молчит — NETWORK', $code ?? '', McpException::NETWORK);
$server->down = false;

Check::group('временный отказ сервера токенов');
$busy = new class implements HttpTransportInterface {
	public function request(string $method, string $url, array $headers = [], string $body = ''): array
	{
		return ['status' => 429, 'headers' => [], 'body' => '{"error":"rate_limited"}'];
	}
};
try { (new OAuthClient('https://box.by', $busy))->refresh('https://box.by/oauth/token', 'c', 'r', 0); } catch(McpException $e) { $code429 = $e->errorCode; }
Check::same('429 — не «войти заново»: подключение не стирается', $code429 ?? '', McpException::SERVER);

Check::group('метаданные');
$evil = new FakeServer();
$evil->meta['token_endpoint'] = 'https://evil.example/token';
Check::throws('адрес токенов на чужом хосте — не доверяем', McpException::class, fn() => (new OAuthClient($evil->base, $evil))->metadata());
Check::same('тот же хост, другой порт — чужой', OAuthClient::sameHost('https://box.by:444/t', 'https://box.by/x'), false);

Check::group('разбор ответа');
Check::same('несколько событий — последнее с результатом', McpClient::parseBody("data: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\n\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"ok\":1}}\n"), ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => 1]]);
Check::same('мусор — null', McpClient::parseBody('<html>'), null);

Check::group('шифрование токенов');
$crypto = new TokenCrypto(TokenCrypto::newKey());
// Токен длинный: короткий («a1») случайно встречался в base64 шифротекста — тест мигал на CI
$token = 'mcp-access-token-7f3c9e2b5a1d4086';
$secret = $crypto->encrypt($token);
Check::same('шифротекст не содержит токена, расшифровывается', [str_contains($secret, $token), $crypto->decrypt($secret)], [false, $token]);
Check::same('другим ключом — null (подключиться заново)', (new TokenCrypto(TokenCrypto::newKey()))->decrypt($secret), null);
Check::same('испорченный — null', $crypto->decrypt(substr($secret, 0, -2).'xx'), null);

Check::finish();
