<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Access;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\Result;
use Shef\ToolsAiAssistant\Access\Model\RoleAccessTable;
use Shef\ToolsAiAssistant\Access\Model\RolePermissionTable;
use Shef\ToolsAiAssistant\Access\Model\RoleTable;
use Shef\ToolsAiAssistant\Main\TableInstaller;
use Shef\ToolsAiAssistant\Prompt\Roles;

/**
 * Роли доступа в базе: три таблицы — роль, её права, кому назначена.
 * Чтение — для проверки прав (UserAccess), запись — со страницы прав
 * (Controller\Access) одной транзакцией.
 */
final class RoleStore
{
	/** Создать таблицы (установка). */
	public static function install(): void
	{
		TableInstaller::install(RoleTable::class);
		TableInstaller::install(RolePermissionTable::class, RolePermissionTable::INDEXES);
		TableInstaller::install(RoleAccessTable::class, RoleAccessTable::INDEXES);
	}

	/** Удалить таблицы (удаление без «сохранить данные»). */
	public static function uninstall(): void
	{
		TableInstaller::uninstall(RoleAccessTable::class);
		TableInstaller::uninstall(RolePermissionTable::class);
		TableInstaller::uninstall(RoleTable::class);
	}

	/**
	 * Заготовки ролей доступа при первой установке — без участников: кому их
	 * назначить, решает администратор (Prompt\Roles::accessPresets()). Роли
	 * уже есть — ничего не делает.
	 */
	public static function seed(): void
	{
		if(RoleTable::getCount() > 0)
		{
			return;
		}
		$form = ['deleted' => [], 'roles' => []];
		foreach(Roles::accessPresets() as $title => $codes)
		{
			$form['roles'][] = [
				'id' => 0,
				'title' => $title,
				'codes' => [],
				'actions' => Actions::normalize($codes),
			];
		}
		static::save($form);
	}

	/**
	 * @return array<int, array{id: int, title: string, codes: list<string>, actions: list<string>}> по ID роли
	 */
	public static function load(): array
	{
		$roles = [];
		foreach(RoleTable::getList(['select' => ['ID', 'NAME'], 'order' => ['ID' => 'ASC']])->fetchAll() as $row)
		{
			$roles[(int)$row['ID']] = ['id' => (int)$row['ID'], 'title' => (string)$row['NAME'], 'codes' => [], 'actions' => []];
		}
		if($roles === [])
		{
			return [];
		}
		foreach(RolePermissionTable::getList(['select' => ['ROLE_ID', 'ACTION'], 'order' => ['ID' => 'ASC']])->fetchAll() as $row)
		{
			if(isset($roles[(int)$row['ROLE_ID']]))
			{
				$roles[(int)$row['ROLE_ID']]['actions'][] = (string)$row['ACTION'];
			}
		}
		foreach(RoleAccessTable::getList(['select' => ['ROLE_ID', 'ACCESS_CODE'], 'order' => ['ID' => 'ASC']])->fetchAll() as $row)
		{
			if(isset($roles[(int)$row['ROLE_ID']]))
			{
				$roles[(int)$row['ROLE_ID']]['codes'][] = (string)$row['ACCESS_CODE'];
			}
		}
		foreach($roles as $id => $role)
		{
			$roles[$id]['actions'] = Actions::normalize($role['actions']);
		}

		return $roles;
	}

	/**
	 * Записать разобранную форму (RoleForm::parse без ошибок): удалённые
	 * роли — прочь, остальные — переписать целиком. Сбой любой строки
	 * откатывает всё сохранение.
	 *
	 * @param array{roles: list<array{id: int, title: string, codes: list<string>, actions: list<string>}>, deleted: list<int>} $form
	 */
	public static function save(array $form): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$deleted = array_map('intval', $form['deleted']);
			foreach($deleted as $id)
			{
				static::clear($id);
				static::check(RoleTable::delete($id));
			}
			$existing = static::load();
			foreach($form['roles'] as $role)
			{
				$id = (int)$role['id'];
				if($id > 0 && (in_array($id, $deleted, true) || !isset($existing[$id])))
				{
					// Роль удалили здесь же или в другой вкладке — не воскрешаем копией
					continue;
				}
				if($id > 0)
				{
					static::check(RoleTable::update($id, ['NAME' => $role['title']]));
					static::clear($id);
				}
				else
				{
					$result = RoleTable::add(['NAME' => $role['title']]);
					static::check($result);
					$id = (int)$result->getId();
				}
				foreach($role['actions'] as $action)
				{
					static::check(RolePermissionTable::add(['ROLE_ID' => $id, 'ACTION' => $action]));
				}
				foreach($role['codes'] as $code)
				{
					static::check(RoleAccessTable::add(['ROLE_ID' => $id, 'ACCESS_CODE' => $code]));
				}
			}
			$connection->commitTransaction();
		}
		catch(\Throwable $throwable)
		{
			$connection->rollbackTransaction();
			throw $throwable;
		}
	}

	/**
	 * Удалить мёртвые права role:<код> у всех ролей доступа (1.6.0): роли
	 * помощника открывает библиотека промптов shef.toolsai, эти строки не
	 * читаются. Зовёт Main\Maintenance при обновлении; повторный вызов —
	 * ничего не делает.
	 */
	public static function purgeLegacy(): void
	{
		$rows = RolePermissionTable::getList(['select' => ['ID'], 'filter' => ['%=ACTION' => Actions::LEGACY_ROLE_PREFIX.'%']])->fetchAll();
		foreach($rows as $row)
		{
			static::check(RolePermissionTable::delete($row['ID']));
		}
	}

	/** Убрать права и участников роли. */
	private static function clear(int $id): void
	{
		foreach([RolePermissionTable::class, RoleAccessTable::class] as $table)
		{
			foreach($table::getList(['select' => ['ID'], 'filter' => ['=ROLE_ID' => $id]])->fetchAll() as $row)
			{
				static::check($table::delete($row['ID']));
			}
		}
	}

	/** Строка не записалась — откатить всё сохранение, а не молча потерять право. */
	private static function check(Result $result): void
	{
		if(!$result->isSuccess())
		{
			throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
		}
	}
}
