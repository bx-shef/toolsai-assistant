<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

/**
 * Единый журнал отзывов линейки — shef.toolsaieval (1.11.0). Реализация —
 * ToolsAiEvalGateway (публичный FeedbackService модуля), в тестах — подмена.
 */
interface EvalGatewayInterface
{
	/** Установлен ли shef.toolsaieval и есть ли его сервис. */
	public function isAvailable(): bool;

	/**
	 * Записать отзыв (поля Shef\ToolsAiEval\Feedback\Entry).
	 *
	 * @param array<string, mixed> $input
	 * @return int|null ID; null — не записан
	 */
	public function record(array $input): ?int;

	/**
	 * Перенести отзыв из своей таблицы.
	 *
	 * @param array<string, mixed> $input
	 * @param array{status?: string, expected?: string, reviewedBy?: int, reviewedAt?: mixed} $review
	 * @return int|null ID; 0 — уже перенесён; null — негодный
	 */
	public function import(array $input, mixed $createdAt, int $sourceId, array $review): ?int;
}
