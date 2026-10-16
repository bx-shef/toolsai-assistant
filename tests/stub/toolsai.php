<?php

/**
 * Подмена публичных классов shef.toolsai, которые зовёт Chat\Gateway\ToolsAiModelGateway.
 *
 * Сигнатуры — как в shef.toolsai 1.49.0 (lib/container.php, lib/quota/ledger.php,
 * lib/provider/llm/*, lib/provider/providerexception.php). Что они есть на самом
 * деле — сверяет tests/toolsaiapi_test.php. Логики нет: модель отвечает тем,
 * что задал тест, журнал запоминает вызовы.
 */

namespace Shef\ToolsAi
{
	class Config
	{
		public const DIRECTION_ASSISTANT = 'assistant';
	}

	class Container
	{
		public static ?Provider\Llm\LlmProviderInterface $llm = null;
		public static bool $exceeded = false;
		public static array $directions = [];

		public static function getLlm(string $direction = 'text'): Provider\Llm\LlmProviderInterface
		{
			static::$directions[] = $direction;

			return static::$llm;
		}

		public static function getMeter(): object
		{
			return new class
			{
				public function getMonthly(): object
				{
					return new class
					{
						public function isExceeded(): bool { return Container::$exceeded; }
					};
				}
			};
		}

		public static function getConfig(): object
		{
			return new class
			{
				public function getAssistantEndpoint(): object { return (object)['model' => 'gpt-test']; }
			};
		}
	}
}

namespace Shef\ToolsAi\Main
{
	class Constants
	{
		public const PROVIDER_ECHO = 'echo';

		public static function getEngineCode(string $category): string
		{
			return 'sheftoolsai_'.$category;
		}
	}
}

namespace Shef\ToolsAi\Quota
{
	class Status
	{
		public const SUCCESS = 'SUCCESS';
		public const ERROR = 'ERROR';
	}

	final class Ledger
	{
		public static array $started = [];
		public static array $finished = [];

		public function start(string $engineCode, string $category, string $providerCode, ?string $jobHash, int $estimateMicro = 0): ?int
		{
			static::$started[] = [$engineCode, $category, $providerCode];

			return count(static::$started);
		}

		public function finish(int $id, string $status, int $units = 0, int $costMicro = 0, ?string $error = null): void
		{
			static::$finished[] = [$id, $status, $units, $costMicro, $error];
		}
	}
}

namespace Shef\ToolsAi\Provider
{
	class ProviderException extends \RuntimeException
	{
		public function __construct(string $message, public readonly string $errorCode = 'provider_error', ?\Throwable $previous = null, public readonly int $spentUnits = 0, public readonly int $spentMicro = 0)
		{
			parent::__construct($message, 0, $previous);
		}
	}
}

namespace Shef\ToolsAi\Provider\Llm
{
	interface LlmProviderInterface
	{
		public function getCode(): string;
		public function complete(array $messages): LlmResult;
	}

	final class LlmResult
	{
		public function __construct(
			public readonly string $text,
			public readonly ?array $json = null,
			public readonly int $tokensIn = 0,
			public readonly int $tokensOut = 0,
			public readonly int $costMicro = 0,
			public readonly string $finishReason = '',
		) {}

		public function getTokens(): int
		{
			return $this->tokensIn + $this->tokensOut;
		}
	}
}
