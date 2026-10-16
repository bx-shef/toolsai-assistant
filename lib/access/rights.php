<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Access;

/**
 * Права одного сотрудника в модуле.
 *
 * Администратор портала может всё: чат и любую роль. Остальным — объединение
 * прав ролей доступа, чьи коды (U<id>, DR<id>) пересекаются с кодами
 * сотрудника (\CAccess::GetUserCodesArray: там уже DR<id> всех отделов выше по
 * дереву). Схема та же, что у прав shef.toolsai (Access\Rights).
 *
 * Чистый класс — без ядра (access_test.php).
 */
final class Rights
{
	/**
	 * @param list<string> $actions
	 */
	private function __construct(
		public readonly bool $isAdmin,
		private readonly array $actions,
	)
	{
	}

	/**
	 * @param string[] $userCodes коды доступа сотрудника
	 * @param array<array{codes: string[], actions: string[]}> $roles роли доступа модуля
	 */
	public static function resolve(bool $isAdmin, array $userCodes, array $roles): self
	{
		if($isAdmin)
		{
			return new self(true, [Actions::CHAT_USE]);
		}

		$userCodes = array_fill_keys(array_map('strval', $userCodes), true);
		$actions = [];
		foreach($roles as $role)
		{
			foreach((array)($role['codes'] ?? []) as $code)
			{
				if(isset($userCodes[(string)$code]))
				{
					array_push($actions, ...array_values((array)($role['actions'] ?? [])));
					break;
				}
			}
		}

		return new self(false, Actions::normalize($actions));
	}

	/** Нет прав: не авторизован или сбой чтения ролей (закрыть надёжнее, чем открыть). */
	public static function none(): self
	{
		return new self(false, []);
	}

	public function can(string $action): bool
	{
		return ($this->isAdmin && $action === Actions::CHAT_USE) || in_array($action, $this->actions, true);
	}

	/** Пользоваться чатом: кнопка, панель, ответы. */
	public function canUseChat(): bool
	{
		return $this->can(Actions::CHAT_USE);
	}

	/** Открыта ли сотруднику роль помощника. Администратору — любая. */
	public function canRole(string $promptCode): bool
	{
		return $this->isAdmin || in_array(Actions::roleRight($promptCode), $this->actions, true);
	}

	/**
	 * Коды открытых ролей; null — все (администратор). Это и есть $allowed для
	 * RegistryService::compose() shef.toolsai.
	 *
	 * @return list<string>|null
	 */
	public function allowedRoles(): ?array
	{
		if($this->isAdmin)
		{
			return null;
		}

		return array_values(array_filter(array_map(static fn(string $a): ?string => Actions::roleOf($a), $this->actions)));
	}

	/** @return list<string> */
	public function getActions(): array
	{
		return $this->actions;
	}
}
