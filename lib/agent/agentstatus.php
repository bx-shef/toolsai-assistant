<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Agent;

use Shef\ToolsAiAssistant\Access\Rights;
use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Container;

/**
 * Что показать в окне про режим «Агент» (1.9.0): кнопка видна, если агент
 * включён в настройках, адрес сервера задан и у сотрудника право agent.use;
 * connected — есть подключение к этому серверу (без похода в сеть).
 */
final class AgentStatus
{
	/** @return array{enabled: bool, connected: bool, server: string} */
	public static function of(Rights $rights, Config $config, int $userId): array
	{
		$enabled = $config->agentReady() && $rights->canUseAgent();

		return [
			'enabled' => $enabled,
			'connected' => $enabled && Container::getMcpConnection($config)->status($userId),
			'server' => $enabled ? $config->agentUrl : '',
		];
	}

	/** @throws ChatException */
	public static function assert(Rights $rights, Config $config): void
	{
		if(!$config->agentReady())
		{
			throw new ChatException('Режим «Агент» выключен в настройках модуля', ChatException::ACCESS);
		}
		if(!$rights->canUseAgent())
		{
			throw new ChatException('Режим «Агент» вам не открыт — обратитесь к администратору портала', ChatException::ACCESS);
		}
	}
}
