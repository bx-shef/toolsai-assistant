<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Internals\Integration\ToolsAiEval\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Access\UserAccess;
use Shef\ToolsAiAssistant\Feedback\EvalBridge;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Обработчик shef.toolsaieval:OnFeedbackAccessCheck (1.12.0): права модуля
 * «Смотреть оценки ответов» и «Разбирать оценки ответов» открывают сотруднику
 * отзывы чата в общем журнале — R и W (решение владельца 2026-10-17: права
 * переходят автоматически, без настройки в shef.toolsaieval). Администратору
 * журнал даёт всё сам. Сбой — без права.
 */
final class OnFeedbackAccessCheckEventHandler
{
	public static function handle(Event $event): EventResult
	{
		try
		{
			$userId = (int)$event->getParameter('userId');
			$right = $userId > 0 ? EvalBridge::right(UserAccess::forUser($userId, false)) : '';
		}
		catch(\Throwable)
		{
			$right = '';
		}

		return new EventResult(EventResult::SUCCESS, ['right' => $right], Constants::MODULE_ID);
	}
}
