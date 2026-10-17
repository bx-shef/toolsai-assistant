<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Public\Event;

use Bitrix\Main\Event;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * shef.toolsaiassistant:OnAfterAgentToolCall (1.10.0) — агент вызвал
 * инструмент MCP. Для журнала и уведомлений; ответ обработчика не читается.
 *
 * Параметры: userId (int), tool (string), title (string), readOnly (bool),
 * arguments (array), ok (bool — сервер не вернул ошибку). Ответ инструмента
 * не передаётся: в нём данные CRM.
 */
final class OnAfterAgentToolCallEvent extends Event
{
	public const TYPE = 'OnAfterAgentToolCall';

	public function __construct(int $userId, array $tool, array $arguments, bool $ok)
	{
		parent::__construct(Constants::MODULE_ID, self::TYPE, [
			'userId' => $userId, 'tool' => $tool['name'], 'title' => $tool['title'], 'readOnly' => $tool['readOnly'], 'arguments' => $arguments, 'ok' => $ok,
		]);
	}
}
