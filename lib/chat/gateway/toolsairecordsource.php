<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

use Shef\ToolsAi\Analysis\RecordHistory;

/**
 * История записи CRM — RecordHistory::get() shef.toolsai (1.45.0+): та же
 * лента, что получает анализ сделок. Права: запись должна быть видна
 * сотруднику в CRM (checkReadPermissions внутри). Сама квоту не тратит.
 */
final class ToolsAiRecordSource implements RecordSourceInterface
{
	public function get(int $entityTypeId, int $id, int $userId): ?array
	{
		$record = RecordHistory::get($entityTypeId, $id, $userId);

		return $record === null ? null : ['title' => $record['title'], 'text' => $record['text']];
	}
}
