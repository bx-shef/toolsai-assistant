<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Хранилище RolePrefs — личные настройки сотрудника ядра (CUserOptions,
 * модуль shef.toolsaiassistant, имя role_prefs). Без ядра (тесты) — память.
 */
final class RolePrefsStore
{
	public const OPTION = 'role_prefs';
	/** @var array<int, mixed> подмена для тестов */
	private static array $memory = [];

	/** @return array{recent: list<string>, favorite: list<string>} */
	public static function load(int $userId): array
	{
		$raw = class_exists(\CUserOptions::class)
			? \CUserOptions::GetOption(Constants::MODULE_ID, self::OPTION, [], $userId)
			: (self::$memory[$userId] ?? []);

		return RolePrefs::normalize($raw);
	}

	public static function save(int $userId, array $prefs): void
	{
		$prefs = RolePrefs::normalize($prefs);
		if(class_exists(\CUserOptions::class))
		{
			\CUserOptions::SetOption(Constants::MODULE_ID, self::OPTION, $prefs, false, $userId);
			return;
		}
		self::$memory[$userId] = $prefs;
	}
}
