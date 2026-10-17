<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

/**
 * Оценка ответа → кейс проверки промптов shef.toolsai (1.5.0).
 *
 * Формат — как у tools/prompt-evals/cases/*.json shef.toolsai, задача «chat»
 * (поддержку задачи раннер shef.toolsai добавляет у себя — что нужно, в
 * docs/guide/08-feedback.md):
 *
 *   {
 *     "id": "assistant-feedback-12.chat",
 *     "task": "chat",
 *     "role": "shef.toolsaiassistant:manager",       // код роли реестра промптов
 *     "question": "Что мешает закрыть сделку?",        // обезличен
 *     "record": {"entityTypeId": 2, "title": "…", "text": "…"} | null,  // запись кратко, обезличена
 *     "expect": {"facts": [["нет шага", "следующего шага"]], "forbidden": ["скидка"]},
 *     "note": "Оценка «не помогло» №12, 17.10.2026. Комментарий: …"
 *   }
 *
 * Берутся только оценки с «что должно было быть» (без фактов судье нечего
 * проверять) и не отклонённые. Всё, что пришло от сотрудника и из CRM, —
 * через Anonymizer.
 *
 * Чистый класс — без ядра (feedback_test.php).
 */
final class EvalCase
{
	public const TASK = 'chat';
	public const ID_PREFIX = 'assistant-feedback-';
	/** Запись CRM в кейсе — кратко: столько знаков истории. */
	public const RECORD_MAX = 1500;

	/** Почему оценка не стала кейсом. */
	public const SKIP_NO_EXPECTED = 'no_expected';
	public const SKIP_REJECTED = 'rejected';
	public const SKIP_TITLES = [
		self::SKIP_NO_EXPECTED => 'не заполнено «что должно было быть»',
		self::SKIP_REJECTED => 'оценка отклонена',
	];

	/**
	 * Кейсы из строк таблицы оценок.
	 *
	 * @param list<array<string, mixed>> $rows строки FeedbackTable (ID, CREATED_AT, ROLE_CODE, CHAT_KEY, QUESTION, RATING, COMMENT, STATUS, EXPECTED)
	 * @param array<int, array{title: string, text: string}|null> $records ID оценки => запись CRM кратко (нет или не видна — null)
	 * @param array<int, list<string>> $names ID оценки => имена, которые убрать (сотрудник и т.п.)
	 * @return array{cases: list<array<string, mixed>>, ids: list<int>, skipped: array<int, string>} ids — какие оценки стали кейсами, skipped — ID => причина
	 */
	public static function fromRows(array $rows, array $records = [], array $names = []): array
	{
		$cases = [];
		$ids = [];
		$skipped = [];
		foreach($rows as $row)
		{
			$id = (int)($row['ID'] ?? 0);
			if((string)($row['STATUS'] ?? '') === Review::REJECTED)
			{
				$skipped[$id] = self::SKIP_REJECTED;
				continue;
			}
			$expect = Review::facts((string)($row['EXPECTED'] ?? ''));
			if($expect['facts'] === [])
			{
				$skipped[$id] = self::SKIP_NO_EXPECTED;
				continue;
			}
			$cases[] = static::fromRow($row, $expect, $records[$id] ?? null, $names[$id] ?? []);
			$ids[] = $id;
		}

		return ['cases' => $cases, 'ids' => $ids, 'skipped' => $skipped];
	}

	/**
	 * Один кейс.
	 *
	 * @param array<string, mixed> $row
	 * @param array{facts: list<list<string>>, forbidden: list<string>} $expect
	 * @param array{title: string, text: string}|null $record
	 * @param list<string> $names
	 * @return array<string, mixed>
	 */
	public static function fromRow(array $row, array $expect, ?array $record, array $names): array
	{
		$id = (int)($row['ID'] ?? 0);
		$clean = static fn(string $text): string => Anonymizer::clean($text, $names);
		$entityTypeId = static::entityTypeId((string)($row['CHAT_KEY'] ?? ''));
		$created = $row['CREATED_AT'] ?? null;
		$date = is_object($created) && method_exists($created, 'format') ? (string)$created->format('d.m.Y') : '';
		$comment = trim((string)($row['COMMENT'] ?? ''));

		$case = [
			'id' => self::ID_PREFIX.$id.'.'.self::TASK,
			'task' => self::TASK,
			'role' => (string)($row['ROLE_CODE'] ?? ''),
			'question' => $clean((string)($row['QUESTION'] ?? '')),
			'record' => null,
			'expect' => [
				'facts' => array_map(static fn(array $variants): array => array_map($clean, $variants), $expect['facts']),
				'forbidden' => array_map($clean, $expect['forbidden']),
			],
			'note' => trim('Оценка «'.mb_strtolower(Rating::title((int)($row['RATING'] ?? 0))).'» №'.$id.($date !== '' ? ', '.$date : '').'.'
				.($comment !== '' ? ' Комментарий: '.$clean($comment) : '')),
		];
		if($entityTypeId !== null)
		{
			$text = $record !== null ? trim($record['text']) : '';
			$case['record'] = [
				'entityTypeId' => $entityTypeId,
				'title' => $record !== null ? $clean(trim($record['title'])) : '',
				'text' => $clean(mb_strlen($text) > self::RECORD_MAX ? rtrim(mb_substr($text, 0, self::RECORD_MAX - 1)).'…' : $text),
			];
		}

		return $case;
	}

	/** JSON набора кейсов: читаемый, кириллица как есть. @param list<array<string, mixed>> $cases */
	public static function json(array $cases): string
	{
		return (string)json_encode($cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	/** Тип записи из ключа чата «record:<тип>:<id>»; не запись — null. */
	public static function entityTypeId(string $chatKey): ?int
	{
		return preg_match('/^record:(\d+):\d+$/', $chatKey, $m) ? (int)$m[1] : null;
	}

	/** ID записи из ключа чата; не запись — null. */
	public static function recordId(string $chatKey): ?int
	{
		return preg_match('/^record:\d+:(\d+)$/', $chatKey, $m) ? (int)$m[1] : null;
	}
}
