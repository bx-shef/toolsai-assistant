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
 * Окно с 1.1.0 печатает ответ по ходу — stream() (LlmService::stream());
 * complete() — ответ одним куском (действие answer, для совместимости).
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

	/** Текст отказа, когда часть ответа уже напечатана. */
	public const BROKEN_TEXT = 'Ответ модели оборвался — спросите ещё раз';

	/** Ответ модели; отказ сервиса — ChatException с текстом для сотрудника. */
	public function complete(array $messages, int $userId = 0): array
	{
		return $this->result($this->llm->complete(LlmService::CATEGORY_ASSISTANT, $messages, $userId > 0 ? $userId : null), false);
	}

	/**
	 * Ответ потоком (LlmService::stream()); отказ — ChatException. Отказ после
	 * напечатанных кусков — «ответ оборвался»: так было в чате shef.toolsai.
	 */
	public function stream(array $messages, callable $onDelta, int $userId = 0): array
	{
		$printed = false;
		$response = $this->llm->stream(LlmService::CATEGORY_ASSISTANT, $messages, static function(string $delta) use ($onDelta, &$printed): void
		{
			if($delta === '')
			{
				return;
			}
			$printed = true;
			$onDelta($delta);
		}, $userId > 0 ? $userId : null);

		return $this->result($response, $printed);
	}

	/** Итог сервиса — текст или ChatException. */
	private function result(\Shef\ToolsAi\Public\Service\LlmResponse $response, bool $printed): array
	{
		if(!$response->isSuccess())
		{
			[$code, $message] = self::ERRORS[$response->getErrorCode()] ?? [ChatException::MODEL, 'Не удалось получить ответ'];
			// Квота и «нет модели» отказывают до вызова — печатать нечего; иначе ответ оборвался
			throw new ChatException($printed && $code === ChatException::MODEL ? self::BROKEN_TEXT : $message, $code);
		}

		return ['text' => $response->getText(), 'finishReason' => $response->getFinishReason() !== '' ? $response->getFinishReason() : 'stop'];
	}

	private function modelCode(): string
	{
		return $this->model ??= $this->llm->model(LlmService::CATEGORY_ASSISTANT);
	}
}
