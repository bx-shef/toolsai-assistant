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

	/** Название модели для подписи в окне. */
	public function modelName(): string;

	/**
	 * Ответ модели. Квота проверяется и расход пишется в журнал shef.toolsai
	 * внутри; исчерпана квота — ChatException::QUOTA.
	 *
	 * @param list<array{role: string, content: string}> $messages
	 * @return array{text: string, finishReason: string}
	 * @param int $userId кто спросил — в лог shef.toolsai при сбое
	 * @throws ChatException квота, модель не ответила — текст для сотрудника
	 */
	public function complete(array $messages, int $userId = 0): array;

	/**
	 * Ответ модели потоком: каждый кусок текста — в $onDelta, как пришёл
	 * (модель без потока — один кусок со всем ответом). Квота и журнал расхода —
	 * как у complete().
	 *
	 * @param list<array{role: string, content: string}> $messages
	 * @param callable(string): void $onDelta
	 * @return array{text: string, finishReason: string} полный текст
	 * @throws ChatException квота, модель не ответила или оборвала ответ
	 */
	public function stream(array $messages, callable $onDelta, int $userId = 0): array;
}
