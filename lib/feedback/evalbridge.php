<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Оценка чата → отзыв общего журнала shef.toolsaieval (1.11.0): поля
 * Shef\ToolsAiEval\Feedback\Entry из разобранной оценки (Rating::parse()) и
 * из строки своей таблицы — для переноса.
 *
 * Чистый класс — без ядра (evalbridge_test.php).
 */
final class EvalBridge
{
	/**
	 * @param array{rating: int, role: string, chatKey: string, question: string, answer: string, comment: string} $rating
	 * @return array<string, mixed>
	 */
	public static function entry(int $userId, array $rating): array
	{
		return [
			'module' => Constants::MODULE_ID,
			'kind' => Constants::EVAL_KIND,
			'rating' => $rating['rating'],
			'userId' => $userId,
			'subject' => $rating['chatKey'],
			'role' => $rating['role'],
			'question' => $rating['question'],
			'answer' => $rating['answer'],
			'comment' => $rating['comment'],
		];
	}

	/**
	 * Строка FeedbackTable → отзыв и разбор для переноса.
	 *
	 * @param array<string, mixed> $row
	 * @return array{entry: array<string, mixed>, review: array<string, mixed>}
	 */
	public static function fromRow(array $row): array
	{
		return [
			'entry' => static::entry((int)$row['USER_ID'], [
				'rating' => (int)$row['RATING'],
				'role' => (string)$row['ROLE_CODE'],
				'chatKey' => (string)$row['CHAT_KEY'],
				'question' => (string)$row['QUESTION'],
				'answer' => (string)$row['ANSWER'],
				'comment' => (string)($row['COMMENT'] ?? ''),
			]),
			'review' => array_filter([
				'status' => Review::status($row['STATUS'] ?? null) ?? Review::NEW,
				'expected' => (string)($row['EXPECTED'] ?? ''),
				'reviewedBy' => isset($row['REVIEWED_BY']) ? (int)$row['REVIEWED_BY'] : null,
				'reviewedAt' => $row['REVIEWED_AT'] ?? null,
			], static fn(mixed $v): bool => $v !== null && $v !== ''),
		];
	}

	/** Вид отзыва для события shef.toolsaieval:OnFeedbackKindsCollect. @return list<array{code: string, title: string, task: string}> */
	public static function kinds(): array
	{
		return [['code' => Constants::EVAL_KIND, 'title' => 'Ответ чата помощника', 'task' => 'chat']];
	}
}
