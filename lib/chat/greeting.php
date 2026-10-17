<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Поздравление сотрудника из виджета birthday (1.11.0): кому и что. Текст
 * сотрудник видит и правит в окне и отправляет в два шага: сначала сервер
 * называет настоящего получателя (имя — с портала, не от модели), потом
 * сотрудник подтверждает. Сервер ещё раз проверяет получателя и текст.
 *
 * Чистый класс — без ядра (controller_test.php).
 */
final class Greeting
{
	/** Предел текста — длинное поздравление в личке никто не дочитает. */
	public const MAX_LENGTH = 2000;
	/** Сколько дней до и после дня рождения поздравление ещё уместно (выходные, отпуск). */
	public const BIRTHDAY_DAYS = 3;
	/** Поздравлений в минуту на сотрудника — защита от рассылки скриптом. */
	public const RATE_LIMIT = 5;

	private function __construct(public readonly int $toUserId, public readonly string $text)
	{
	}

	/**
	 * Разбор запроса окна; негодное — ChatException с кодом greeting. BB-коды
	 * мессенджера ([URL=…], [USER=…]) вырезаются: модель не спрячет ссылку за
	 * текстом, который сотрудник видит в поле.
	 */
	public static function parse(int $fromUserId, mixed $toUserId, mixed $text): self
	{
		$to = is_numeric($toUserId) ? (int)$toUserId : 0;
		if($to <= 0 || (string)$to !== trim((string)$toUserId))
		{
			throw new ChatException('Не понятно, кого поздравить', ChatException::GREETING);
		}
		if($to === $fromUserId)
		{
			throw new ChatException('Себе поздравление не отправить', ChatException::GREETING);
		}
		$text = is_string($text) ? $text : '';
		$text = preg_replace('/\[\/?[a-z*]+(?:=[^\]]*)?\]/iu', '', $text) ?? '';
		$text = trim(str_replace("\r\n", "\n", $text));
		if($text === '')
		{
			throw new ChatException('Напишите текст поздравления', ChatException::GREETING);
		}
		if(mb_strlen($text) > self::MAX_LENGTH)
		{
			throw new ChatException('Поздравление длиннее '.self::MAX_LENGTH.' знаков', ChatException::GREETING);
		}

		return new self($to, $text);
	}

	/**
	 * День рождения ($birthday — «Y-m-d», год любой) не дальше BIRTHDAY_DAYS
	 * дней от $today, через Новый год тоже. 29 февраля в невисокосный год — 28-е.
	 */
	public static function isNearBirthday(?string $birthday, \DateTimeImmutable $today): bool
	{
		if($birthday === null || !preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $birthday, $m))
		{
			return false;
		}
		$today = $today->setTime(0, 0);
		$year = (int)$today->format('Y');
		foreach([$year - 1, $year, $year + 1] as $y)
		{
			$day = (int)$m[2];
			if((int)$m[1] === 2 && $day === 29 && !checkdate(2, 29, $y))
			{
				$day = 28;
			}
			if(!checkdate((int)$m[1], $day, $y))
			{
				return false;
			}
			$date = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, (int)$m[1], $day), $today->getTimezone());
			if(abs((int)$today->diff($date)->format('%r%a')) <= self::BIRTHDAY_DAYS)
			{
				return true;
			}
		}

		return false;
	}
}
