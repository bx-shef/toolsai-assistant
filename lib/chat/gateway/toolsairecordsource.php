<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

use Shef\ToolsAi\Public\Service\RecordService;

/**
 * История записи CRM — публичный API shef.toolsai 1.50.0
 * (Public\Service\RecordService::get()): лента касаний, запись должна быть
 * видна сотруднику в CRM. Квоту не тратит.
 */
final class ToolsAiRecordSource implements RecordSourceInterface
{
	/** История записи с правами CRM сотрудника; не видна или нет — null. */
	public function get(int $entityTypeId, int $id, int $userId): ?array
	{
		$record = RecordService::get($entityTypeId, $id, $userId);

		return $record === null ? null : ['title' => (string)$record['title'], 'text' => (string)$record['text']];
	}
}
