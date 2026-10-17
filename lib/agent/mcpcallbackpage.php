<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Agent;

use Bitrix\Main\Context;
use Shef\ToolsAiAssistant\Access\UserAccess;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Container;
use Shef\ToolsAiAssistant\Mcp\McpException;

/**
 * Страница возврата из входа в MCP-сервер (1.9.0). Сотрудник — текущий
 * пользователь портала; state сверяет McpConnection::finish() по сессии.
 * Итог — окну чата через postMessage (только своему сайту) и текстом на
 * странице; окно закрывается само.
 */
final class McpCallbackPage
{
	public const MESSAGE_TYPE = 'shef-toolsaiassistant-mcp';

	public static function show(): void
	{
		global $USER;
		$userId = is_object($USER) ? (int)$USER->GetID() : 0;
		$ok = false;
		$text = 'Войдите на портал и повторите подключение из чата.';
		if($userId > 0)
		{
			try
			{
				$config = Config::current();
				AgentStatus::assert(UserAccess::current(), $config);
				Container::getMcpConnection($config)->finish($userId, Context::getCurrent()->getRequest()->getQueryList()->toArray());
				$ok = true;
				$text = 'Подключено. Можно вернуться в чат — окно закроется само.';
			}
			catch(McpException|\Shef\ToolsAiAssistant\Chat\ChatException $exception)
			{
				$text = $exception->getMessage();
			}
			catch(\Throwable $throwable)
			{
				Container::getLogger(static::class)?->error($throwable);
				$text = 'Не удалось подключиться — подробности в журнале модуля.';
			}
		}

		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store');
		header('Referrer-Policy: no-referrer');
		echo static::html($ok, $text);
	}

	/** Страница итога (чистая, agent_test.php). */
	public static function html(bool $ok, string $text): string
	{
		$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$payload = json_encode(['type' => self::MESSAGE_TYPE, 'ok' => $ok], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

		return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Подключение к MCP</title>'
			.'<style>body{font:15px/1.5 system-ui,sans-serif;margin:3rem auto;max-width:32rem;padding:0 1rem;color:#333}h1{font-size:1.3rem}</style></head><body>'
			.'<h1>'.($ok ? '✓ Агент подключён' : 'Подключение не удалось').'</h1><p>'.$h($text).'</p>'
			.'<script>(function(){try{if(window.opener){window.opener.postMessage('.$payload.',window.location.origin);}}catch(e){}'
			.($ok ? 'setTimeout(function(){window.close();},1500);' : '').'})();</script></body></html>';
	}
}
