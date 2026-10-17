<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Mcp;

/**
 * Начатый вход в MCP — в сессии сотрудника: колбэк вернётся в тот же браузер,
 * и чужая ссылка возврата код не обменяет (state не совпадёт).
 */
final class SessionStateStore implements StateStoreInterface
{
	private const PREFIX = 'shef_taia_mcp_';

	public function get(string $key): ?array
	{
		$value = \Bitrix\Main\Application::getInstance()->getSession()->get(self::PREFIX.$key);

		return is_array($value) ? $value : null;
	}

	public function set(string $key, ?array $value): void
	{
		$session = \Bitrix\Main\Application::getInstance()->getSession();
		if($value === null)
		{
			$session->remove(self::PREFIX.$key);

			return;
		}
		$session->set(self::PREFIX.$key, $value);
	}
}
