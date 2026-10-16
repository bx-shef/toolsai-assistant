<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

use Shef\ToolsAi\Config;
use Shef\ToolsAi\Container as ToolsAiContainer;
use Shef\ToolsAi\Main\Constants as ToolsAiConstants;
use Shef\ToolsAi\Provider\Llm\LlmProviderInterface;
use Shef\ToolsAi\Provider\ProviderException;
use Shef\ToolsAi\Quota\Ledger;
use Shef\ToolsAi\Quota\Status;
use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Container;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Вызов модели через публичный путь shef.toolsai — навык
 * shef-new-ai-provider, раздел «Вызвать LLM из своего модуля»:
 *
 *   модель   — Container::getLlm(Config::DIRECTION_ASSISTANT): направление
 *              «Чат ИИ-помощника» настроек провайдера (как было у чата в
 *              shef.toolsai); провайдер-заглушка — EchoLlm;
 *   квота    — Container::getMeter()->getMonthly()->isExceeded() до вызова;
 *   расход   — Quota\Ledger: start() до вызова и finish() со статусом
 *              Status::SUCCESS / Status::ERROR после — категория «assistant»,
 *              код движка Main\Constants::getEngineCode('assistant').
 *
 * Ответ — одним куском (complete()): контроллер отвечает JSON. Поток
 * (OpenAi\Llm::completeStream()) в интерфейс LLM shef.toolsai не входит.
 */
final class ToolsAiModelGateway implements ModelGatewayInterface
{
	private ?LlmProviderInterface $llm = null;

	public function isStub(): bool
	{
		return $this->llm()->getCode() === ToolsAiConstants::PROVIDER_ECHO;
	}

	public function isQuotaExceeded(): bool
	{
		return ToolsAiContainer::getMeter()->getMonthly()->isExceeded();
	}

	public function modelName(): string
	{
		if($this->isStub())
		{
			return 'заглушка (модель не подключена)';
		}
		$model = ToolsAiContainer::getConfig()->getAssistantEndpoint()->model;

		return $model !== '' ? $model : 'ИИ-модель';
	}

	public function complete(array $messages): array
	{
		$llm = $this->llm();
		$ledger = new Ledger();
		$ledgerId = $ledger->start(ToolsAiConstants::getEngineCode(Constants::LEDGER_CATEGORY), Constants::LEDGER_CATEGORY, $llm->getCode(), null, 0);
		try
		{
			$result = $llm->complete($messages);
		}
		catch(ProviderException $exception)
		{
			$ledgerId !== null && $ledger->finish($ledgerId, Status::ERROR, $exception->spentUnits, $exception->spentMicro, mb_substr($exception->getMessage(), 0, 500));
			Container::getLogger(self::class)?->error($exception);
			throw new ChatException('Модель не ответила — попробуйте позже; подробности в журнале модуля', ChatException::MODEL, $exception);
		}
		catch(\Throwable $throwable)
		{
			$ledgerId !== null && $ledger->finish($ledgerId, Status::ERROR, 0, 0, mb_substr($throwable->getMessage(), 0, 500));
			Container::getLogger(self::class)?->error($throwable);
			throw new ChatException('Не удалось получить ответ', ChatException::MODEL, $throwable);
		}

		$empty = trim($result->text) === '';
		$ledgerId !== null && $ledger->finish($ledgerId, $empty ? Status::ERROR : Status::SUCCESS, $result->getTokens(), $result->costMicro, $empty ? 'Пустой ответ модели' : null);
		if($empty)
		{
			throw new ChatException('Модель ответила пустым текстом — попробуйте переформулировать вопрос', ChatException::MODEL);
		}

		return ['text' => $result->text, 'finishReason' => $result->finishReason !== '' ? $result->finishReason : 'stop'];
	}

	private function llm(): LlmProviderInterface
	{
		return $this->llm ??= ToolsAiContainer::getLlm(Config::DIRECTION_ASSISTANT);
	}
}
