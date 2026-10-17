<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Shef\ToolsAiAssistant\Access\UserAccess;
use Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiRecordSource;
use Shef\ToolsAiAssistant\Container;
use Shef\ToolsAiAssistant\Feedback\EvalCase;
use Shef\ToolsAiAssistant\Feedback\FeedbackPage;
use Shef\ToolsAiAssistant\Feedback\FeedbackStore;
use Shef\ToolsAiAssistant\Feedback\Review;

/**
 * Разбор оценок ответов (1.4.0): BX.ajax.runAction('shef:toolsaiassistant.feedback.<действие>').
 *
 *   status — групповое действие грида: «Разобрано», «Отклонить», «Вернуть в новые»;
 *   expect — «что должно было быть» из окна оценки;
 *   export — выгрузить отмеченные кейсами проверки промптов shef.toolsai
 *            (Feedback\EvalCase); выгруженные получают статус «В наборе проверки».
 *
 * Все — POST, авторизация, sessid (фильтры ядра; свой run() не объявляем — в
 * ядре он final). Право — feedback.edit (UserAccess), проверяется на каждом
 * вызове.
 */
class Feedback extends Controller
{
	/** Фильтры: авторизация, только POST, sessid. */
	public function configureActions(): array
	{
		$filters = [
			new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Csrf(),
		];

		return [
			'status' => ['prefilters' => $filters],
			'expect' => ['prefilters' => $filters],
			'export' => ['prefilters' => $filters],
		];
	}

	/**
	 * Статус отмеченным. «В наборе проверки» руками не ставится — только выгрузкой.
	 *
	 * @param list<int|string> $ids
	 * @return array{updated: int, status: string}|null
	 */
	public function statusAction(array $ids = [], string $status = ''): ?array
	{
		return $this->guarded(function() use ($ids, $status): ?array
		{
			$status = Review::status($status);
			$ids = Review::ids($ids);
			if($status === null || !in_array($status, Review::MANUAL, true) || $ids === [])
			{
				return $this->fail('Не выбраны оценки или статус', 'bad_request');
			}

			return ['updated' => $this->store()->setStatus($ids, $status, $this->userId()), 'status' => $status];
		});
	}

	/**
	 * «Что должно было быть»: по факту на строку, варианты — через «|», «!» — чего быть не должно.
	 *
	 * @return array{id: int, status: string, expected: string}|null
	 */
	public function expectAction(int $id = 0, string $expected = ''): ?array
	{
		return $this->guarded(function() use ($id, $expected): ?array
		{
			$expected = Review::expected($expected);
			$status = $id > 0 ? $this->store()->setExpected($id, $expected, $this->userId()) : null;
			if($status === null)
			{
				return $this->fail('Оценка не найдена', 'not_found');
			}

			return ['id' => $id, 'status' => $status, 'expected' => $expected];
		});
	}

	/**
	 * Кейсы проверки из отмеченных оценок: JSON для tools/prompt-evals/cases
	 * shef.toolsai. Запись CRM — кратко и только видимая выгружающему в CRM.
	 *
	 * @param list<int|string> $ids
	 * @return array{cases: list<array<string, mixed>>, json: string, exported: list<int>, skipped: array<int, string>, reasons: array<string, string>}|null
	 */
	public function exportAction(array $ids = []): ?array
	{
		return $this->guarded(function() use ($ids): ?array
		{
			$ids = Review::ids($ids);
			if($ids === [])
			{
				return $this->fail('Не выбраны оценки', 'bad_request');
			}
			$store = $this->store();
			$rows = $store->byIds($ids);
			$userId = $this->userId();
			$users = FeedbackPage::userNames(array_column($rows, 'USER_ID'));
			$records = [];
			$names = [];
			$source = new ToolsAiRecordSource();
			foreach($rows as $row)
			{
				$rowId = (int)$row['ID'];
				$names[$rowId] = array_values(array_filter([$users[(int)$row['USER_ID']] ?? '']));
				$type = EvalCase::entityTypeId((string)$row['CHAT_KEY']);
				$recordId = EvalCase::recordId((string)$row['CHAT_KEY']);
				if($type === null || $recordId === null || trim((string)($row['EXPECTED'] ?? '')) === '')
				{
					continue;
				}
				try
				{
					$records[$rowId] = $source->get($type, $recordId, $userId);
				}
				catch(\Throwable $throwable)
				{
					Container::getLogger(static::class)?->warning($throwable->getMessage());
					$records[$rowId] = null;
				}
			}
			$result = EvalCase::fromRows($rows, $records, $names);
			if($result['ids'] !== [])
			{
				$store->setStatus($result['ids'], Review::EVAL, $userId);
			}

			return [
				'cases' => $result['cases'],
				'json' => EvalCase::json($result['cases']),
				'exported' => $result['ids'],
				'skipped' => $result['skipped'],
				'reasons' => EvalCase::SKIP_TITLES,
			];
		});
	}

	/** Право feedback.edit; отказ — ошибкой ответа. Сбой — в лог, наружу общий текст. */
	private function guarded(callable $action): ?array
	{
		if(!UserAccess::current()->canEditFeedback())
		{
			return $this->fail('Нет права «Разбирать оценки ответов»', 'access_denied');
		}
		try
		{
			return $action();
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(static::class)?->error($throwable);

			return $this->fail('Не удалось сохранить — подробности в журнале модуля', 'internal');
		}
	}

	private function fail(string $message, string $code): null
	{
		$this->addError(new Error($message, $code));

		return null;
	}

	private function store(): FeedbackStore
	{
		return new FeedbackStore();
	}

	private function userId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}
}
