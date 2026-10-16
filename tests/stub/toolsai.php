<?php

/**
 * Подмена публичного API shef.toolsai 1.50.0 (Shef\ToolsAi\Public\Service\…),
 * которое зовёт Chat\Gateway\ToolsAiModelGateway.
 *
 * Сигнатуры — как в shef.toolsai (lib/public/service/llmservice.php,
 * llmresponse.php); что они есть на самом деле — сверяет
 * tests/toolsaiapi_test.php. Логики нет: ответ задаёт тест, вызовы
 * запоминаются.
 */

namespace Shef\ToolsAi\Public\Service
{
	final class LlmResponse extends \Bitrix\Main\Result
	{
		public function __construct(private readonly string $text = '', private readonly string $finishReason = '', private readonly int $costMicro = 0, private readonly int $tokens = 0) {}

		public static function failed(string $code, string $message): self
		{
			$response = new self();
			$response->addError(new \Bitrix\Main\Error($message, $code));

			return $response;
		}

		public function getText(): string { return $this->text; }
		public function getFinishReason(): string { return $this->finishReason; }
		public function getCostMicro(): int { return $this->costMicro; }
		public function getTokens(): int { return $this->tokens; }
		public function getErrorCode(): string { return (string)($this->getErrors()[0]?->getCode() ?? ''); }
	}

	class LlmService
	{
		public const CATEGORY_ASSISTANT = 'assistant';
		public const ERROR_CATEGORY = 'bad_category';
		public const ERROR_MESSAGES = 'bad_messages';
		public const ERROR_NO_MODEL = 'no_model';
		public const ERROR_QUOTA = 'quota_exceeded';
		public const ERROR_PROVIDER = 'provider_error';
		public const ERROR_EMPTY = 'empty_answer';
		public const ERROR_INTERNAL = 'internal_error';

		public static ?LlmResponse $next = null;
		public static string $model = 'gpt-test';
		public static array $calls = [];

		public function model(string $category): string
		{
			return static::$model;
		}

		public function complete(string $category, array $messages, ?int $userId = null): LlmResponse
		{
			static::$calls[] = [$category, count($messages), $userId];

			return static::$next;
		}
	}
}
