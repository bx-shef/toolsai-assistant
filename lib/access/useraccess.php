<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Access;

use Bitrix\Main\Application;
use Shef\ToolsAiAssistant\Container;

/**
 * Права текущего сотрудника из ролей доступа в базе.
 *
 * Коды сотрудника — \CAccess::GetUserCodesArray (там уже DR<id> всех отделов
 * выше по дереву). Роли общие для всех — читаются раз в час (управляемый кеш
 * ядра) и сбрасываются при сохранении страницы прав: кнопку чата
 * проверяют на каждой странице CRM. Сбой чтения — прав нет, кроме
 * администратора.
 */
final class UserAccess
{
	private const CACHE_ID = 'shef_toolsaiassistant_roles';
	private const CACHE_DIR = 'shef_toolsaiassistant';
	private const CACHE_TTL = 3600;

	/** @var array<int, Rights> */
	private static array $cache = [];

	/** Права текущего пользователя; не авторизован — прав нет. */
	public static function current(): Rights
	{
		$user = $GLOBALS['USER'] ?? null;
		if(!$user instanceof \CUser || !$user->IsAuthorized())
		{
			return Rights::none();
		}

		return static::forUser((int)$user->GetID(), (bool)$user->IsAdmin());
	}

	/** Права сотрудника по ID. */
	public static function forUser(int $userId, bool $isAdmin): Rights
	{
		if($isAdmin)
		{
			return Rights::resolve(true, [], []);
		}
		if($userId <= 0)
		{
			return Rights::none();
		}
		if(isset(self::$cache[$userId]))
		{
			return self::$cache[$userId];
		}
		try
		{
			$rights = Rights::resolve(false, \CAccess::GetUserCodesArray($userId), static::roles());
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(self::class)?->error($throwable);
			$rights = Rights::none();
		}

		return self::$cache[$userId] = $rights;
	}

	/** Сбросить после сохранения ролей. */
	public static function reset(): void
	{
		self::$cache = [];
		try
		{
			Application::getInstance()->getManagedCache()->clean(self::CACHE_ID, self::CACHE_DIR);
		}
		catch(\Throwable)
		{
		}
	}

	/** Роли из кеша или базы. */
	private static function roles(): array
	{
		$cache = Application::getInstance()->getManagedCache();
		if($cache->read(self::CACHE_TTL, self::CACHE_ID, self::CACHE_DIR))
		{
			$roles = $cache->get(self::CACHE_ID);
			if(is_array($roles))
			{
				return $roles;
			}
		}
		$roles = RoleStore::load();
		$cache->set(self::CACHE_ID, $roles);

		return $roles;
	}
}
