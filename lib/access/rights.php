<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Access;

/**
 * Права одного сотрудника в модуле.
 *
 * Администратор портала получает все права модуля (чат, оценки). Роли
 * помощника здесь не раздаются — с 1.6.0 их открывает библиотека промптов
 * shef.toolsai («Кому доступна»), и администратор подчиняется ей так же,
 * как все: закрытая ему роль в чате не видна. Остальным — объединение
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
			return new self(true, array_keys(Actions::getList()));
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

	/** Есть ли право (Actions::CHAT_USE, FEEDBACK_VIEW, FEEDBACK_EDIT). Администратору — все. */
	public function can(string $action): bool
	{
		return ($this->isAdmin && isset(Actions::getList()[$action])) || in_array($action, $this->actions, true);
	}

	/** Режим «Агент»: инструменты MCP от имени сотрудника (1.9.0). */
	public function canUseAgent(): bool
	{
		return $this->can(Actions::AGENT_USE);
	}

	/** Смотреть оценки ответов (1.1.0). */
	public function canViewFeedback(): bool
	{
		return $this->can(Actions::FEEDBACK_VIEW);
	}

	/** Разбирать оценки ответов: статус, ожидаемый ответ, выгрузка кейсов (1.5.0). */
	public function canEditFeedback(): bool
	{
		return $this->can(Actions::FEEDBACK_EDIT);
	}

	/** Пользоваться чатом: кнопка, панель, ответы. */
	public function canUseChat(): bool
	{
		return $this->can(Actions::CHAT_USE);
	}

	/** @return list<string> */
	public function getActions(): array
	{
		return $this->actions;
	}
}
