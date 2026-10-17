<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

use Shef\ToolsAiAssistant\Chat\Gateway\EvalGatewayInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\FeedbackStoreInterface;

/**
 * Куда пишется оценка ответа (1.11.0): есть shef.toolsaieval — в общий
 * журнал отзывов линейки (разбор и выгрузка кейсов — там); нет или он не
 * записал — в свою таблицу, как до 1.11.0: оценка не теряется. Отметки
 * частоты — свои, как раньше.
 */
final class FeedbackRouter implements FeedbackStoreInterface
{
	public function __construct(
		private readonly FeedbackStoreInterface $local,
		private readonly EvalGatewayInterface $eval,
	)
	{
	}

	public function add(int $userId, array $rating): int
	{
		try
		{
			$id = $this->eval->isAvailable() ? $this->eval->record(EvalBridge::entry($userId, $rating)) : null;
		}
		catch(\Throwable)
		{
			$id = null;
		}

		return $id ?? $this->local->add($userId, $rating);
	}

	public function stamps(int $userId): array
	{
		return $this->local->stamps($userId);
	}

	public function putStamps(int $userId, array $stamps): void
	{
		$this->local->putStamps($userId, $stamps);
	}
}
