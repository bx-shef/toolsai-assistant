<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Public\Event;

use Bitrix\Main\Event;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * shef.toolsaiassistant:OnAgentToolsCollect (1.9.0) — правила для инструментов
 * MCP в режиме «Агент» перед каждым вопросом.
 *
 * Параметры: userId (int), tools (list<string> — имена инструментов сервера).
 * Ответ обработчика:
 *
 *   new EventResult(EventResult::SUCCESS, [
 *       'hide' => ['im_message_send'],                 // убрать из чата
 *       'confirm' => ['crm_item_list'],                // спрашивать и на чтение
 *       'hints' => ['task_create' => 'Срок ставь в рабочие часы'],  // подсказка модели
 *   ], 'мой.модуль');
 *
 * Разбор — Agent\ToolCatalog::build(); контракт — docs/guide/04-events.md.
 */
final class OnAgentToolsCollectEvent extends Event
{
	public const TYPE = 'OnAgentToolsCollect';

	/** @param list<string> $tools */
	public function __construct(int $userId, array $tools)
	{
		parent::__construct(Constants::MODULE_ID, self::TYPE, ['userId' => $userId, 'tools' => $tools]);
	}
}
