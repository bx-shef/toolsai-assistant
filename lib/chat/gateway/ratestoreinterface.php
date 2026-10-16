<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

/**
 * Отметки времени вызовов модели сотрудником — для Chat\RateLimit.
 * Реализация — UserOptionRateStore; в тестах — подмена.
 */
interface RateStoreInterface
{
	/** @return list<int> */
	public function get(int $userId): array;

	/** @param list<int> $stamps */
	public function put(int $userId, array $stamps): void;
}
