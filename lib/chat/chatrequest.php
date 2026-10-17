<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Запрос на ответ от окна чата: переписка, запись CRM, выбранная роль.
 *
 * Браузер шлёт messages: [{role: 'user'|'assistant', text}], record:
 * {entityTypeId, id}, page — путь открытой страницы (1.13.0) и role — код роли (пусто — запомненная или по
 * умолчанию). Берутся последние $history сообщений, вопрос — последнее
 * сообщение сотрудника. Перенос логики Kb\ChatStream::parse() shef.toolsai —
 * под простой формат окна модуля (без частей AI SDK).
 *
 * Чистый класс — без ядра (chat_test.php).
 */
final class ChatRequest
{
	public const MESSAGE_MAX = 4000;
	public const ROLE_MAX = 150;

	/**
	 * @param list<array{role: string, content: string}> $history
	 */
	public function __construct(
		public readonly array $history,
		public readonly string $question,
		public readonly ?Record $record,
		public readonly string $role,
		/** Контекст окна: «docs» — справка (1.3.0, ChatKey::of()). */
		public readonly string $context = '',
		/** 1.8.0: текст файлов сотрудника из сообщений переписки (ChatFiles, общий предел). */
		public readonly array $files = [],
		/** 1.13.0: страница основного окна или слайдера (PageContext). */
		public readonly ?PageContext $page = null,
	)
	{
	}

	/** Разбор параметров действия. */
	public static function parse(mixed $messages, mixed $record, mixed $role, int $history, mixed $context = '', mixed $page = null): self
	{
		$list = [];
		foreach(is_array($messages) ? $messages : [] as $message)
		{
			if(!is_array($message))
			{
				continue;
			}
			$author = $message['role'] ?? '';
			if($author !== 'user' && $author !== 'assistant')
			{
				continue;
			}
			$text = is_string($message['text'] ?? null) ? trim(mb_substr($message['text'], 0, self::MESSAGE_MAX)) : '';
			if($text === '')
			{
				continue;
			}
			// Файлы — только у вопросов сотрудника (1.8.0)
			$list[] = ['role' => $author, 'content' => $text, 'files' => $author === 'user' ? ChatFiles::fromMessage($message['files'] ?? null) : []];
		}
		$list = array_slice($list, -max(1, $history));
		// Модель ждёт, что разговор начинается с сотрудника: ответ без вопроса — прочь
		while($list !== [] && $list[0]['role'] !== 'user')
		{
			array_shift($list);
		}

		$question = '';
		foreach(array_reverse($list) as $message)
		{
			if($message['role'] === 'user')
			{
				$question = $message['content'];
				break;
			}
		}

		$role = is_string($role) ? strtolower(trim(mb_substr($role, 0, self::ROLE_MAX))) : '';
		$files = ChatFiles::limit(array_column($list, 'files'));
		$list = array_map(static fn(array $m): array => ['role' => $m['role'], 'content' => $m['content']], $list);

		return new self($list, $question, Record::fromArray($record), $role, ChatKey::context($context), $files, PageContext::fromPath($page));
	}
}
