<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

/**
 * Хранилище оценок ответов (1.1.0). Реализация — Feedback\FeedbackStore
 * (таблица Feedback\Model\FeedbackTable), в тестах — подмена.
 */
interface FeedbackStoreInterface
{
	/**
	 * Сохранить оценку; вернуть её ID.
	 *
	 * @param array{rating: int, role: string, chatKey: string, question: string, answer: string, comment: string} $rating разобранная Feedback\Rating::parse()
	 */
	public function add(int $userId, array $rating): int;

	/** @return list<int> время прошлых оценок сотрудника (ограничение частоты) */
	public function stamps(int $userId): array;

	/** @param list<int> $stamps */
	public function putStamps(int $userId, array $stamps): void;
}
