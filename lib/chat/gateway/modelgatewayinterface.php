<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

use Shef\ToolsAiAssistant\Chat\ChatException;

/**
 * Вызов модели — через shef.toolsai с учётом расхода и квоты.
 * Реализация — ToolsAiModelGateway.
 */
interface ModelGatewayInterface
{
	/** Модель не подключена (заглушка shef.toolsai): ответ не нужен и не стоит денег. */
	public function isStub(): bool;

	/** Месячная квота shef.toolsai исчерпана. */
	public function isQuotaExceeded(): bool;

	/** Название модели для подписи в окне. */
	public function modelName(): string;

	/**
	 * Ответ модели. Расход записан в журнал shef.toolsai при любом исходе.
	 *
	 * @param list<array{role: string, content: string}> $messages
	 * @return array{text: string, finishReason: string}
	 * @throws ChatException модель не ответила — текст для сотрудника
	 */
	public function complete(array $messages): array;
}
