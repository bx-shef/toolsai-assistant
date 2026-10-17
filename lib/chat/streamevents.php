<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * События потока ответа (text/event-stream, строки «data: {json}\n\n») — как
 * поток чата shef.toolsai до 1.50.0 (Kb\ChatStream), но проще: окно своё, не
 * AI SDK.
 *
 *   start  {role, sources}        — проверки пройдены, модель позвали;
 *   delta  {text}                 — кусок ответа, как пришёл;
 *   finish {finishReason}         — ответ целиком (окно сохраняет переписку);
 *   error  {code, message}        — отказ посреди ответа (текст — для сотрудника).
 *
 * Чистый класс — без ядра (stream_test.php).
 */
final class StreamEvents
{
	/** Заголовки ответа: поток без кеша и без буфера nginx. */
	public const HEADERS = [
		'Content-Type' => 'text/event-stream; charset=utf-8',
		'Cache-Control' => 'no-cache, no-store',
		'X-Accel-Buffering' => 'no',
	];

	/** @param list<array{title: string, url: string}> $sources */
	public static function start(string $role, array $sources): string
	{
		return static::line(['type' => 'start', 'role' => $role, 'sources' => $sources]);
	}

	/** Кусок ответа модели. */
	public static function delta(string $text): string
	{
		return static::line(['type' => 'delta', 'text' => $text]);
	}

	/** Ответ закончен: stop, length (обрезан лимитом длины)… */
	public static function finish(string $finishReason): string
	{
		return static::line(['type' => 'finish', 'finishReason' => $finishReason !== '' ? $finishReason : 'stop']);
	}

	/** Отказ посреди потока. */
	public static function error(string $code, string $message): string
	{
		return static::line(['type' => 'error', 'code' => $code, 'message' => $message]);
	}

	/**
	 * Разобрать поток обратно в события — для тестов и чтобы держать формат в
	 * одном месте с chat.js.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function parse(string $body): array
	{
		$events = [];
		foreach(preg_split('/\n\n/', $body) ?: [] as $block)
		{
			if(str_starts_with($block, 'data: '))
			{
				$event = json_decode(substr($block, 6), true);
				if(is_array($event))
				{
					$events[] = $event;
				}
			}
		}

		return $events;
	}

	/** Одна строка события; JSON без переводов строк — разделитель событий не встретится внутри. */
	private static function line(array $event): string
	{
		return 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
	}
}
