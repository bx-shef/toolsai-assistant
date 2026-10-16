<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

/**
 * Статьи базы знаний под вопрос. Реализация — KbSource (shef.toolsaikb,
 * мягко): модуля нет или сбой — пустой список, чат работает без статей.
 */
interface KbSourceInterface
{
	/** Подключён ли модуль базы знаний. */
	public function isAvailable(): bool;

	/**
	 * @return list<array{id: string, title: string, url: string, snippet: string}>
	 */
	public function find(string $query, int $limit): array;
}
