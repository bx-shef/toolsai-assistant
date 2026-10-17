<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Поздравление сотрудника из виджета birthday (1.11.0): кому и что. Текст
 * сотрудник видит и правит в окне и отправляет кнопкой «Отправить» с
 * подтверждением; сервер ещё раз проверяет получателя и текст.
 *
 * Чистый класс — без ядра (controller_test.php).
 */
final class Greeting
{
	/** Предел текста — длинное поздравление в личке никто не дочитает. */
	public const MAX_LENGTH = 2000;

	private function __construct(public readonly int $toUserId, public readonly string $text)
	{
	}

	/** Разбор запроса окна; негодное — ChatException с кодом greeting. */
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
		$text = trim(str_replace("\r\n", "\n", is_string($text) ? $text : ''));
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
}
