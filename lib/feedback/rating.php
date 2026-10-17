<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

use Shef\ToolsAiAssistant\Access\Actions;
use Shef\ToolsAiAssistant\Chat\ChatKey;
use Shef\ToolsAiAssistant\Chat\Record;

/**
 * Оценка ответа помощника «полезно / не помогло» — разбор того, что прислало
 * окно чата (как оценки чата в shef.toolsai до 1.50.0: Kb::rateAction,
 * Feedback\Model\FeedbackTable).
 *
 * Строго: оценка — только «полезно» (1) или «не помогло» (-1), роль — код
 * промпта, вопрос и ответ обязательны. Тексты обрезаются: в журнал оценок
 * идёт «кратко», не вся переписка.
 *
 * Чистый класс — без ядра (feedback_test.php).
 */
final class Rating
{
	public const UP = 1;
	public const DOWN = -1;
	/** Сколько знаков вопроса, ответа и комментария хранить. */
	public const QUESTION_MAX = 1000;
	public const ANSWER_MAX = 2000;
	public const COMMENT_MAX = 1000;
	/** Не больше оценок одним сотрудником за минуту: защищает таблицу от потока. */
	public const RATE_LIMIT = 30;

	/**
	 * Разобрать присланную оценку; мусор — null.
	 *
	 * @param array<string, mixed> $input rating (up|down|1|-1), role, record {entityTypeId, id}, question, answer, comment
	 * @return array{rating: int, role: string, chatKey: string, question: string, answer: string, comment: string}|null
	 */
	public static function parse(array $input): ?array
	{
		$rating = match((string)($input['rating'] ?? '')) {
			'up', '1' => self::UP,
			'down', '-1' => self::DOWN,
			default => null,
		};
		$role = strtolower(trim((string)($input['role'] ?? '')));
		$question = static::text($input['question'] ?? '', self::QUESTION_MAX);
		$answer = static::text($input['answer'] ?? '', self::ANSWER_MAX);
		if($rating === null || !preg_match(Actions::PROMPT_CODE_PATTERN, $role) || $question === '' || $answer === '')
		{
			return null;
		}

		return [
			'rating' => $rating,
			'role' => $role,
			'chatKey' => ChatKey::of(Record::fromArray($input['record'] ?? null)),
			'question' => $question,
			'answer' => $answer,
			'comment' => static::text($input['comment'] ?? '', self::COMMENT_MAX),
		];
	}

	/** Подпись оценки для списка. */
	public static function title(int $rating): string
	{
		return $rating === self::UP ? 'Полезно' : 'Не помогло';
	}

	/** Строка без управляющих символов (кроме перевода строки), обрезанная до $max знаков. */
	private static function text(mixed $value, int $max): string
	{
		$value = is_scalar($value) ? (string)$value : '';
		$value = trim((string)preg_replace('/[^\P{C}\n]+/u', '', $value));

		return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max - 1)).'…' : $value;
	}
}
