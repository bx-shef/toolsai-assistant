<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Access;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Shef\ToolsAiAssistant\Main\Constants;
use Shef\ToolsAiAssistant\Prompt\Roles;

/**
 * Разовый перенос прав чата shef.toolsai до 1.50.0.
 *
 * В shef.toolsai 1.44–1.49 чат выдавали права «Чат ИИ-помощника: спрашивать»
 * (assistant.use) и «…: справа в виде «ИИ»» (assistant.panel). В 1.50.0 их нет,
 * но строки могли остаться в его таблицах прав (shef_toolsai_access_permission,
 * shef_toolsai_access_code). Кому они были выданы, получает роль доступа
 * «Чат (перенос из shef.toolsai)»: «Пользоваться чатом» и роль «Помощник
 * менеджера» — так чат у этих сотрудников не пропадает и отвечает одной ролью,
 * как раньше. Один раз — отметка в настройке модуля; таблиц нет — ничего.
 *
 * Разбор — plan(), чистый (legacyrights_test.php).
 */
final class LegacyRights
{
	public const OPTION_DONE = 'LEGACY_migrated';
	public const ROLE_TITLE = 'Чат (перенос из shef.toolsai)';
	public const LEGACY_ACTIONS = ['assistant.use', 'assistant.panel'];
	public const TABLE_PERMISSION = 'shef_toolsai_access_permission';
	public const TABLE_CODE = 'shef_toolsai_access_code';

	/**
	 * Коды доступа (U/DR), которым был выдан чат shef.toolsai.
	 *
	 * @param list<array{ROLE_ID: mixed, ACTION: mixed}> $permissions строки прав shef.toolsai
	 * @param list<array{ROLE_ID: mixed, ACCESS_CODE: mixed}> $codes кому назначены его роли
	 * @return list<string> без повторов, только допустимые коды (RoleForm::CODE_PATTERN)
	 */
	public static function plan(array $permissions, array $codes): array
	{
		$roles = [];
		foreach($permissions as $row)
		{
			if(in_array((string)($row['ACTION'] ?? ''), self::LEGACY_ACTIONS, true))
			{
				$roles[(int)$row['ROLE_ID']] = true;
			}
		}
		$out = [];
		foreach($codes as $row)
		{
			$code = (string)($row['ACCESS_CODE'] ?? '');
			if(isset($roles[(int)($row['ROLE_ID'] ?? 0)]) && preg_match(RoleForm::CODE_PATTERN, $code) && !in_array($code, $out, true))
			{
				$out[] = $code;
			}
		}

		return $out;
	}

	/** Перенести, если ещё не переносили и таблицы shef.toolsai есть. */
	public static function migrate(): void
	{
		if(Option::get(Constants::MODULE_ID, self::OPTION_DONE, '') === 'Y')
		{
			return;
		}
		$connection = Application::getConnection();
		if($connection->isTableExists(self::TABLE_PERMISSION) && $connection->isTableExists(self::TABLE_CODE))
		{
			$codes = static::plan(
				$connection->query('SELECT ROLE_ID, ACTION FROM '.self::TABLE_PERMISSION)->fetchAll(),
				$connection->query('SELECT ROLE_ID, ACCESS_CODE FROM '.self::TABLE_CODE)->fetchAll()
			);
			if($codes !== [])
			{
				RoleStore::save(['deleted' => [], 'roles' => [[
					'id' => 0,
					'title' => self::ROLE_TITLE,
					'codes' => $codes,
					'actions' => Actions::normalize([Actions::CHAT_USE, Actions::roleRight(Roles::MANAGER)]),
				]]]);
				UserAccess::reset();
			}
		}
		Option::set(Constants::MODULE_ID, self::OPTION_DONE, 'Y');
	}
}
