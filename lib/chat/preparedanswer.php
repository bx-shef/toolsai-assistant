<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Ответ, готовый к вызову модели: все проверки (право на чат, роль, частота,
 * запись CRM) уже пройдены — AnswerService::prepare(). Дальше — complete()
 * одним куском или stream() потоком.
 *
 * $messages пуст и $stubText задан — модель не подключена: ответ известен
 * без вызова.
 */
final class PreparedAnswer
{
	/**
	 * @param list<array{role: string, content: string}> $messages сообщения для модели
	 * @param list<array{title: string, url: string}> $sources статьи базы знаний для ответа
	 */
	public function __construct(
		public readonly int $userId,
		public readonly string $chatKey,
		public readonly string $role,
		public readonly array $messages,
		public readonly array $sources,
		public readonly ?string $stubText = null,
	)
	{
	}
}
