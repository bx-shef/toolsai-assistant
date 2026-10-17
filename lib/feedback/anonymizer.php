<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

/**
 * Обезличить текст перед выгрузкой кейсом проверки (1.4.0): кейсы уезжают в
 * репозиторий shef.toolsai и к провайдеру модели при прогоне.
 *
 * Заменяет на маркеры:
 *   почта                    → [почта];
 *   телефон (10–15 цифр)     → [телефон];
 *   известные имена          → [имя] — сотрудник, поставивший оценку, и
 *                              другие, кого передали (каждое слово имени тоже);
 *   «Имя Фамилия (Отчество)» → [имя] — два-три слова с большой буквы подряд;
 *                              первое слово предложения в счёт не идёт.
 *
 * Это страховка, не гарантия: название компании одним словом или имя в
 * начале предложения останутся. Человек просматривает кейс перед тем, как
 * положить его в набор (docs/guide).
 *
 * Чистый класс — без ядра (feedback_test.php).
 */
final class Anonymizer
{
	public const EMAIL = '[почта]';
	public const PHONE = '[телефон]';
	public const NAME = '[имя]';

	/** Слово с большой буквы: кириллица или латиница, с дефисом («Римский-Корсаков»). */
	private const WORD = '\p{Lu}\p{Ll}+(?:-\p{Lu}\p{Ll}+)?';

	/**
	 * @param list<string> $names известные имена: «Иван Петров», «Петров»
	 */
	public static function clean(string $text, array $names = []): string
	{
		$text = (string)preg_replace('/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u', self::EMAIL, $text);
		$text = (string)preg_replace_callback('/(?<![\p{L}\p{N}])\+?\d[\d\s\-().]{8,}\d(?![\p{L}\p{N}])/u', static function(array $m): string
		{
			$digits = strlen((string)preg_replace('/\D/', '', $m[0]));

			return $digits >= 10 && $digits <= 15 ? self::PHONE : $m[0];
		}, $text);

		foreach(static::nameWords($names) as $word)
		{
			$text = (string)preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', self::NAME, $text);
		}

		$text = (string)preg_replace_callback('/'.self::WORD.'(?:\s+'.self::WORD.'){1,3}/u', static function(array $m) use ($text): string
		{
			[$match, $offset] = $m[0];
			$before = rtrim(substr($text, 0, $offset));
			$words = preg_split('/\s+/u', $match) ?: [];
			// Первое слово предложения — не имя («Клиент Анна Котова» → «Клиент [имя]»)
			$first = $before === '' || preg_match('/[.!?…:\n«"(]$/u', $before) ? array_shift($words) : null;

			return count($words) >= 2 ? ($first !== null ? $first.' ' : '').self::NAME : $match;
		}, $text, -1, $count, PREG_OFFSET_CAPTURE);

		// «[имя] [имя]» от слов одного имени — в один маркер
		return (string)preg_replace('/'.preg_quote(self::NAME, '/').'(?:\s+'.preg_quote(self::NAME, '/').')+/u', self::NAME, $text);
	}

	/**
	 * Имена и их слова, длинные — первыми (чтобы «Иван Петров» заменился целиком).
	 *
	 * @param list<string> $names
	 * @return list<string>
	 */
	private static function nameWords(array $names): array
	{
		$words = [];
		foreach($names as $name)
		{
			$name = trim((string)preg_replace('/\s+/u', ' ', (string)$name));
			if(mb_strlen($name) < 2 || str_starts_with($name, '#'))
			{
				continue;
			}
			$words[$name] = true;
			foreach(explode(' ', $name) as $word)
			{
				if(mb_strlen($word) >= 3)
				{
					$words[$word] = true;
				}
			}
		}
		$words = array_keys($words);
		usort($words, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

		return $words;
	}
}
