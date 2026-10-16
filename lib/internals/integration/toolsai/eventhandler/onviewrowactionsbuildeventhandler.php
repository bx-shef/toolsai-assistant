<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Internals\Integration\ToolsAi\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Main\Constants;
use Shef\ToolsAiAssistant\Public\Service\ChatPanel;

/**
 * Обработчик shef.toolsai:OnViewRowActionsBuild (1.50.0) — пункт «✦ Спросить
 * ИИ» в меню строки вида «ИИ»: открывает панель чата по этой записи.
 *
 * Контракт задаёт shef.toolsai (Public\Event\ViewRowActionsBuildEvent):
 * параметры entityTypeId, id, title; ответ — EventResult SUCCESS с
 * ['actions' => [['text', 'onclick']]], не больше трёх пунктов; onclick
 * экранирует обработчик (ChatPanel::openRecordJs). Типизирован Main\Event, а
 * не классом события shef.toolsai: так обработчик не падает, если класс
 * переименуют. Нет права на чат — пусто.
 */
final class OnViewRowActionsBuildEventHandler
{
	/** Пункт «✦ Спросить ИИ» для строки; без права на чат или без записи — пустой список. */
	public static function handle(Event $event): EventResult
	{
		$entityTypeId = (int)$event->getParameter('entityTypeId');
		$id = (int)$event->getParameter('id');
		$actions = [];
		if($entityTypeId > 0 && $id > 0 && ChatPanel::canUse())
		{
			$title = trim((string)$event->getParameter('title'));
			$actions[] = ['text' => ChatPanel::ROW_ACTION_TEXT, 'onclick' => ChatPanel::openRecordJs($entityTypeId, $id, $title !== '' ? $title : '#'.$id)];
		}

		return new EventResult(EventResult::SUCCESS, ['actions' => $actions], Constants::MODULE_ID);
	}
}
