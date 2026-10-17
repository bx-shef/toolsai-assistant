<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Internals\Integration\ToolsAi\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Main\Constants;
use Shef\ToolsAiAssistant\Prompt\ChatPlaces;

/**
 * Обработчик shef.toolsai:OnPromptPlacesCollect (1.7.0): места чата помощника
 * для «Где доступен» в мастере библиотеки промптов — администратор кладёт свой
 * промпт в меню промптов чата (везде, у списка CRM, в карточке записи).
 *
 * Регистрируется установщиком (installEvents в .settings.php).
 */
final class OnPromptPlacesCollectEventHandler
{
	public static function handle(Event $event): EventResult
	{
		return new EventResult(EventResult::SUCCESS, ['places' => ChatPlaces::forLibrary()], Constants::MODULE_ID);
	}
}
