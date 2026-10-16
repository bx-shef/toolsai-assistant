<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

use Shef\ToolsAi\Public\Service\LlmService;
use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Вызов модели — публичный API shef.toolsai 1.50.0
 * (Public\Service\LlmService, глава docs/guide/32-public-api.md shef.toolsai):
 * модель направления «Чат ИИ-помощника», квота до вызова, журнал расхода
 * (категория assistant) — внутри complete(). Исключений сервис не бросает:
 * отказ — код LlmService::ERROR_*, его переводим в ChatException.
 *
 * Ответ — одним куском (complete()): контроллер отвечает JSON. stream() у
 * сервиса есть — понадобится, когда окно перейдёт на поток.
 */
final class ToolsAiModelGateway implements ModelGatewayInterface
{
	/** Код отказа LlmService => [код ChatException, текст для сотрудника]. */
	public const ERRORS = [
		LlmService::ERROR_QUOTA => [ChatException::QUOTA, 'Месячная квота ИИ исчерпана — обратитесь к администратору портала'],
		LlmService::ERROR_NO_MODEL => [ChatException::MODEL, 'Модель не подключена — обратитесь к администратору портала'],
		LlmService::ERROR_EMPTY => [ChatException::MODEL, 'Модель ответила пустым текстом — попробуйте переформулировать вопрос'],
		LlmService::ERROR_PROVIDER => [ChatException::MODEL, 'Модель не ответила — попробуйте позже; подробности в журнале shef.toolsai'],
	];

	private ?string $model = null;

	public function __construct(private readonly LlmService $llm = new LlmService())
	{
	}

	/** Модель не подключена (заглушка или нет лицензии shef.toolsai). */
	public function isStub(): bool
	{
		return $this->modelCode() === '';
	}

	/** Название модели для подписи под окном. */
	public function modelName(): string
	{
		return $this->isStub() ? 'заглушка (модель не подключена)' : $this->modelCode();
	}

	/** Ответ модели; отказ сервиса — ChatException с текстом для сотрудника. */
	public function complete(array $messages, int $userId = 0): array
	{
		$response = $this->llm->complete(LlmService::CATEGORY_ASSISTANT, $messages, $userId > 0 ? $userId : null);
		if(!$response->isSuccess())
		{
			[$code, $message] = self::ERRORS[$response->getErrorCode()] ?? [ChatException::MODEL, 'Не удалось получить ответ'];
			throw new ChatException($message, $code);
		}

		return ['text' => $response->getText(), 'finishReason' => $response->getFinishReason() !== '' ? $response->getFinishReason() : 'stop'];
	}

	private function modelCode(): string
	{
		return $this->model ??= $this->llm->model(LlmService::CATEGORY_ASSISTANT);
	}
}
