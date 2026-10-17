<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

use Bitrix\Main\Config\Option;
use Shef\ToolsAiAssistant\Chat\Gateway\EvalGatewayInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiEvalGateway;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Перенос оценок из своей таблицы в общий журнал shef.toolsaieval (1.11.0).
 *
 * Пачками по ID: дата, разбор («что должно было быть», статус, кто разобрал)
 * и номер оценки уходят в FeedbackService::import(); повторный перенос того
 * же номера там ничего не пишет — сбой посреди пачки безопасен. Докуда
 * дошли — опция FEEDBACK_evalmigrated (последний перенесённый ID). Своя
 * таблица не трогается: удалить её — решение владельца, после проверки.
 *
 * Зовут агент очистки (раз в сутки) и страница оценок. step() — чистая часть
 * (evalbridge_test.php).
 */
final class EvalMigration
{
	public const OPTION_LAST_ID = 'FEEDBACK_evalmigrated';
	public const BATCH = 200;
	/** Не больше пачек за вызов: страница не должна ждать. */
	public const MAX_BATCHES = 10;

	/**
	 * Одна пачка.
	 *
	 * @param list<array<string, mixed>> $rows строки FeedbackTable с ID больше последнего, по возрастанию ID
	 * @return array{lastId: int, moved: int, failed: list<int>}
	 */
	public static function step(array $rows, int $lastId, EvalGatewayInterface $eval): array
	{
		$moved = 0;
		$failed = [];
		foreach($rows as $row)
		{
			$id = (int)$row['ID'];
			$data = EvalBridge::fromRow($row);
			try
			{
				$result = $eval->import($data['entry'], $row['CREATED_AT'] ?? null, $id, $data['review']);
			}
			catch(\Throwable)
			{
				// Сбой базы — остановиться здесь: следующий вызов начнёт с этой строки
				return ['lastId' => $lastId, 'moved' => $moved, 'failed' => $failed];
			}
			if($result === null)
			{
				// Негодная строка (нет сотрудника и т.п.) — пропустить, чтобы не застрять навсегда
				$failed[] = $id;
			}
			else
			{
				$moved += $result > 0 ? 1 : 0;
			}
			$lastId = $id;
		}

		return ['lastId' => $lastId, 'moved' => $moved, 'failed' => $failed];
	}

	/**
	 * Перенести, что осталось (не больше MAX_BATCHES пачек). Нет
	 * shef.toolsaieval — ничего.
	 *
	 * @return int сколько перенесено
	 */
	public static function run(?EvalGatewayInterface $eval = null, ?FeedbackStore $store = null): int
	{
		$eval ??= new ToolsAiEvalGateway();
		if(!$eval->isAvailable())
		{
			return 0;
		}
		$store ??= new FeedbackStore();
		$lastId = (int)Option::get(Constants::MODULE_ID, self::OPTION_LAST_ID, '0');
		$moved = 0;
		for($i = 0; $i < self::MAX_BATCHES; $i++)
		{
			$rows = $store->after($lastId, self::BATCH);
			if($rows === [])
			{
				break;
			}
			$step = static::step($rows, $lastId, $eval);
			$moved += $step['moved'];
			if($step['lastId'] === $lastId)
			{
				break;
			}
			$lastId = $step['lastId'];
			Option::set(Constants::MODULE_ID, self::OPTION_LAST_ID, (string)$lastId);
		}

		return $moved;
	}

	/** Сколько ещё не перенесено (для подсказки на странице). */
	public static function left(?FeedbackStore $store = null): int
	{
		return ($store ?? new FeedbackStore())->count(['>ID' => (int)Option::get(Constants::MODULE_ID, self::OPTION_LAST_ID, '0')]);
	}
}
