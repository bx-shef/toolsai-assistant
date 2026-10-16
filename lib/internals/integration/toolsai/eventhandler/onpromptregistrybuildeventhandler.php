<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Internals\Integration\ToolsAi\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Main\Constants;
use Shef\ToolsAiAssistant\Prompt\Roles;

/**
 * Обработчик shef.toolsai:OnPromptRegistryBuild — роли помощника в реестр
 * промптов shef.toolsai.
 *
 * Контракт события задаёт отправитель (shef.toolsai,
 * lib/prompt/registryservice.php, RegistryService::defaults()): обычный
 * Main\Event без параметров; ответ — EventResult SUCCESS с ['prompts' =>
 * [...]] и ID модуля-владельца. Коды чужого модуля реестр отбрасывает,
 * поэтому все коды — «shef.toolsaiassistant:…».
 *
 * Регистрируется установщиком (installEvents в .settings.php), снимается
 * при удалении.
 */
final class OnPromptRegistryBuildEventHandler
{
	/** Ответ реестру: шесть ролей помощника. */
	public static function handle(Event $event): EventResult
	{
		return new EventResult(EventResult::SUCCESS, ['prompts' => Roles::raw()], Constants::MODULE_ID);
	}
}
