<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Agent;

/**
 * Вызовы, ждущие «Выполнить» (1.9.0). Сам вызов (инструмент и аргументы)
 * хранится на сервере: окно присылает только id и решение, подменить
 * аргументы оно не может. Вместе с вызовом — шаги вопроса (trace): после
 * «Выполнить» модель продолжит с ними, даже если страницу перезагрузили.
 * «Забрать» — одноразово и атомарно (две вкладки не выполнят дважды).
 * На портале — PendingStore (своя таблица).
 */
interface PendingStoreInterface
{
	/** @param array{tool: string, title: string, arguments: array<string, mixed>, trace: list<array>, at: int} $call */
	public function put(int $userId, string $id, array $call): void;

	/** Забрать вызов (одноразово); нет или чужой — null. */
	public function take(int $userId, string $id): ?array;
}
