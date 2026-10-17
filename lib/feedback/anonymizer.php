<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

/**
 * Обезличить текст перед выгрузкой кейсом проверки (1.5.0): кейсы уезжают в
 * репозиторий shef.toolsai и к провайдеру модели при прогоне.
 *
 * Заменяет на маркеры (по порядку):
 *   почта, и записанная «ivan (at) mail (dot) ru», «ivan собака mail.ru» → [почта];
 *   номер карты (16–19 цифр, можно группами)                         → [карта];
 *   паспорт, ИНН, СНИЛС, КПП, ОГРН — номер после слова, СНИЛС по виду → [документ];
 *   телефон: 10–15 цифр; 7–9 цифр — с «-», скобками или «123 45 67»   → [телефон];
 *   адрес по маркерам: ул., улица, пр-т, пер., б-р, шоссе, наб. с
 *   «д., корп., стр., кв., оф.», и «д. 5, кв. 7» отдельно             → [адрес];
 *   известные имена (сотрудник, ФИО контакта и лида, компания,
 *   ответственный записи — RecordNames) и каждое их слово               → [имя];
 *   инициалы: «Петров И.И.», «И. И. Петров»                           → [имя];
 *   два-три слова ЗАГЛАВНЫМИ («ИВАН ПЕТРОВ»), кроме сокращений (ООО…)   → [имя];
 *   два-три слова с большой буквы подряд («Котова Анна»). В начале
 *   предложения первое слово из списка обычных («Клиент», «Сделка»,
 *   «Что»…) — не имя: «Клиент Анна Котова» → «Клиент [имя]».
 *
 * Это страховка, не гарантия: название компании одним словом в тексте без
 * записи останется. Человек просматривает файл перед тем, как положить его в
 * набор (docs/guide/08-feedback.md).
 *
 * Чистый класс — без ядра (feedback_test.php).
 */
final class Anonymizer
{
	public const EMAIL = '[почта]';
	public const PHONE = '[телефон]';
	public const CARD = '[карта]';
	public const DOCUMENT = '[документ]';
	public const ADDRESS = '[адрес]';
	public const NAME = '[имя]';

	/** Слово с большой буквы: кириллица или латиница, с дефисом («Римский-Корсаков»). */
	private const WORD = '\p{Lu}\p{Ll}+(?:-\p{Lu}\p{Ll}+)?';
	/** Не буква и не цифра перед / после. */
	private const L = '(?<![\p{L}\p{N}])';
	private const R = '(?![\p{L}\p{N}])';

	/** Обычные первые слова предложения: после них одно слово с большой буквы — не имя. */
	public const STARTERS = ['что', 'как', 'почему', 'зачем', 'где', 'когда', 'кто', 'какой', 'какие', 'это', 'по', 'в', 'на', 'для', 'и', 'но', 'а', 'не', 'да', 'нет',
		'сделка', 'сделке', 'лид', 'лиду', 'компания', 'компании', 'запись', 'счёт', 'счет', 'ответ', 'вопрос', 'итог', 'статус', 'этап', 'город', 'проект', 'задача', 'звонок'];
	/** Первые слова-роли: после них даже одно слово с большой буквы — имя («Клиент Анна»). */
	public const PERSON_STARTERS = ['клиент', 'клиентка', 'менеджер', 'контакт', 'звонил', 'звонила', 'директор', 'сотрудник', 'сотрудница', 'ответственный', 'руководитель', 'бухгалтер', 'писал', 'писала', 'просил', 'просила'];
	/** Сокращения ЗАГЛАВНЫМИ — не имена. */
	public const ABBREVIATIONS = ['ООО', 'ОАО', 'ЗАО', 'ПАО', 'ИП', 'АО', 'НДС', 'ИНН', 'КПП', 'ОГРН', 'СНИЛС', 'CRM', 'РФ', 'РБ', 'ТОВ', 'ЧУП', 'УНП', 'ЛПР', 'КП', 'ТЗ', 'SLA', 'API', 'USD', 'EUR', 'РУБ', 'BYN'];

	/**
	 * @param list<string> $names известные имена: «Иван Петров», «ООО Ромашка»
	 */
	public static function clean(string $text, array $names = []): string
	{
		$text = static::emails($text);
		$text = static::cards($text);
		$text = static::documents($text);
		$text = static::phones($text);
		$text = static::addresses($text);
		$text = static::knownNames($text, $names);
		$text = static::initials($text);
		$text = static::upperNames($text);
		$text = static::capitalNames($text);

		// «[имя] [имя]» от слов одного имени — в один маркер
		return (string)preg_replace('/'.preg_quote(self::NAME, '/').'(?:\s+'.preg_quote(self::NAME, '/').')+/u', self::NAME, $text);
	}

	private static function emails(string $text): string
	{
		$text = (string)preg_replace('/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u', self::EMAIL, $text);
		// Нестандартная запись: (at) [at] {at} « at » «собака», точка — (dot) [dot] « dot » « точка »
		$at = '\s*(?:[(\[{]\s*(?:at|собака)\s*[)\]}]|\s(?:at|собака)\s)\s*';
		$dot = '\s*(?:\.|[(\[{]\s*(?:dot|точка)\s*[)\]}]|\s(?:dot|точка)\s)\s*';

		return (string)preg_replace('/'.self::L.'[\p{L}\p{N}._%+\-]+'.$at.'[\p{L}\p{N}\-]+(?:'.$dot.'[\p{L}\p{N}\-]+)*'.$dot.'\p{L}{2,}'.self::R.'/iu', self::EMAIL, $text);
	}

	private static function cards(string $text): string
	{
		return (string)preg_replace_callback('/'.self::L.'\d(?:[ \-]?\d){15,18}'.self::R.'/u', static function(array $m): string
		{
			$digits = strlen((string)preg_replace('/\D/', '', $m[0]));

			return $digits >= 16 && $digits <= 19 ? self::CARD : $m[0];
		}, $text);
	}

	private static function documents(string $text): string
	{
		// Номер после слова: «паспорт 4506 123456», «ИНН: 7701234567», «СНИЛС № 123-456-789 01»
		$text = (string)preg_replace('/('.self::L.'(?:паспорт[а-я]*|серия|ИНН|КПП|ОГРН(?:ИП)?|СНИЛС|УНП)\s*(?:[:№#]|номер)?\s*)\d[\d \-]{3,}\d/iu', '$1'.self::DOCUMENT, $text);

		// СНИЛС по виду: 123-456-789 01
		return (string)preg_replace('/'.self::L.'\d{3}-\d{3}-\d{3}[ \-]\d{2}'.self::R.'/u', self::DOCUMENT, $text);
	}

	private static function phones(string $text): string
	{
		return (string)preg_replace_callback('/'.self::L.'(?:\+|\()?\d[\d \-()]{5,}\d'.self::R.'/u', static function(array $m): string
		{
			$digits = strlen((string)preg_replace('/\D/', '', $m[0]));
			if($digits >= 10 && $digits <= 15)
			{
				return self::PHONE;
			}
			// 7–9 цифр — телефон только с разделителем-дефисом или скобками (иначе это сумма)
			// или группами телефона «123 45 67» (сумма «1 500 000» — группами по три)
			return $digits >= 7 && $digits <= 9 && (preg_match('/[\-()]/', $m[0]) || preg_match('/\d{2,3} \d{2} \d{2}$/', $m[0])) ? self::PHONE : $m[0];
		}, $text);
	}

	private static function addresses(string $text): string
	{
		$part = '(?:,?\s*(?:д\.|дом|корп\.|к\.|стр\.|кв\.|квартира|оф\.|офис)\s*\d+[\p{L}\d\/\-]*)';
		$text = (string)preg_replace('/'.self::L.'(?:ул\.|улица|пр-т|просп\.|проспект|пер\.|переулок|б-р|бульвар|шоссе|наб\.|набережная|пл\.|площадь)\s*[^,.;\n]+'.$part.'*/iu', self::ADDRESS, $text);

		return (string)preg_replace('/'.self::L.'(?:д\.|дом)\s*\d+[\p{L}\d\/\-]*'.$part.'+/iu', self::ADDRESS, $text);
	}

	/** @param list<string> $names */
	private static function knownNames(string $text, array $names): string
	{
		foreach(static::nameWords($names) as $word)
		{
			$text = (string)preg_replace('/'.self::L.preg_quote($word, '/').self::R.'/iu', self::NAME, $text);
		}

		return $text;
	}

	private static function initials(string $text): string
	{
		$text = (string)preg_replace('/'.self::L.self::WORD.'\s+\p{Lu}\.\s?(?:\p{Lu}\.)?/u', self::NAME, $text);

		$text = (string)preg_replace('/'.self::L.'\p{Lu}\.\s?(?:\p{Lu}\.\s?)?'.self::WORD.'/u', self::NAME, $text);

		// Фамилию уже заменили известным именем — инициалы за ней тоже
		return (string)preg_replace('/'.preg_quote(self::NAME, '/').'\s+\p{Lu}\.\s?(?:\p{Lu}\.)?/u', self::NAME, $text);
	}

	private static function upperNames(string $text): string
	{
		return (string)preg_replace_callback('/'.self::L.'\p{Lu}{2,}(?:\s+\p{Lu}{2,}){1,2}'.self::R.'/u', static function(array $m): string
		{
			$words = array_values(array_filter(preg_split('/\s+/u', $m[0]) ?: [], static fn(string $w): bool => !in_array($w, self::ABBREVIATIONS, true)));
			$long = array_filter($words, static fn(string $w): bool => mb_strlen($w) >= 3);

			return count($long) >= 2 ? self::NAME : $m[0];
		}, $text);
	}

	private static function capitalNames(string $text): string
	{
		return (string)preg_replace_callback('/'.self::L.self::WORD.'(?:\s+'.self::WORD.'){0,3}/u', static function(array $m) use ($text): string
		{
			[$match, $offset] = $m[0];
			$before = rtrim(substr($text, 0, $offset));
			$words = preg_split('/\s+/u', $match) ?: [];
			$atStart = $before === '' || preg_match('/[.!?…:\n«"(]$/u', $before) === 1;
			if(!$atStart)
			{
				return count($words) >= 2 ? self::NAME : $match;
			}
			$first = mb_strtolower($words[0]);
			if(in_array($first, self::PERSON_STARTERS, true))
			{
				return count($words) >= 2 ? $words[0].' '.self::NAME : $match;
			}
			if(in_array($first, self::STARTERS, true))
			{
				return count($words) >= 3 ? $words[0].' '.self::NAME : $match;
			}

			return count($words) >= 2 ? self::NAME : $match;
		}, $text, -1, $count, PREG_OFFSET_CAPTURE);
	}

	/**
	 * Имена и их слова, длинные — первыми (чтобы «Иван Петров» заменился целиком).
	 * Слова короче 3 знаков и сокращения (ООО) отдельно не заменяются.
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
			foreach(preg_split('/[\s"«»,]+/u', $name) ?: [] as $word)
			{
				if(mb_strlen($word) >= 3 && !in_array(mb_strtoupper($word), self::ABBREVIATIONS, true))
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
