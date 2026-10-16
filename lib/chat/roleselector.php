<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Какой ролью отвечать.
 *
 * Роль, которую прислал браузер, — только из открытых сотруднику (иначе
 * отказ: это и есть проверка права роли на каждом ответе). Не прислал —
 * запомненная для этого чата, если ещё открыта; иначе роль по умолчанию,
 * если открыта; иначе первая открытая. Открытых нет — null.
 *
 * Чистый класс — без ядра (chat_test.php).
 */
final class RoleSelector
{
	/**
	 * @param list<string> $available коды открытых сотруднику включённых ролей
	 * @throws ChatException роль прислана, но сотруднику не открыта
	 */
	public static function choose(array $available, string $requested, ?string $saved, string $default): ?string
	{
		if($requested !== '')
		{
			if(!in_array($requested, $available, true))
			{
				throw new ChatException('Эта роль помощника вам не открыта — выберите другую или обратитесь к администратору портала', ChatException::ROLE);
			}

			return $requested;
		}
		foreach([$saved, $default] as $candidate)
		{
			if($candidate !== null && in_array($candidate, $available, true))
			{
				return $candidate;
			}
		}

		return $available[0] ?? null;
	}
}
