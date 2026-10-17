<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Internals\Integration\ToolsAiEval\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Feedback\EvalBridge;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Обработчик shef.toolsaieval:OnFeedbackKindsCollect (1.12.0): вид отзыва
 * «Ответ чата помощника», задача проверки промптов — chat. Модуль вида — в
 * ответе события (третий аргумент EventResult). Без shef.toolsaieval событие
 * просто не придёт.
 */
final class OnFeedbackKindsCollectEventHandler
{
	public static function handle(Event $event): EventResult
	{
		return new EventResult(EventResult::SUCCESS, ['kinds' => EvalBridge::kinds()], Constants::MODULE_ID);
	}
}
