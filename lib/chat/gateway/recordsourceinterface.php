<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

/**
 * История записи CRM для модели. Реализация — ToolsAiRecordSource
 * (RecordHistory::get() shef.toolsai, права CRM сотрудника).
 */
interface RecordSourceInterface
{
	/**
	 * Текст истории записи; запись не найдена, тип не поддерживается или
	 * сотруднику не видна в CRM — null.
	 *
	 * @return array{title: string, text: string}|null
	 */
	public function get(int $entityTypeId, int $id, int $userId): ?array;
}
