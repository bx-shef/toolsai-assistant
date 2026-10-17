<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Public\Event;

use Bitrix\Main\Event;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * shef.toolsaiassistant:OnBeforeAgentToolCall (1.9.0) — агент сейчас вызовет
 * инструмент MCP (чтение — сам, запись — после «Выполнить»).
 *
 * Параметры: userId (int), tool (string), title (string), readOnly (bool),
 * arguments (array). Запретить:
 *
 *   new EventResult(EventResult::ERROR, ['message' => 'Сделки этой воронки меняет только РОП'], 'мой.модуль');
 *
 * Причина уйдёт модели и будет видна сотруднику в чате.
 */
final class OnBeforeAgentToolCallEvent extends Event
{
	public const TYPE = 'OnBeforeAgentToolCall';

	public function __construct(int $userId, array $tool, array $arguments)
	{
		parent::__construct(Constants::MODULE_ID, self::TYPE, [
			'userId' => $userId, 'tool' => $tool['name'], 'title' => $tool['title'], 'readOnly' => $tool['readOnly'], 'arguments' => $arguments,
		]);
	}
}
