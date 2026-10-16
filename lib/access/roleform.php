<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Access;

/**
 * Разбор того, что присылает страница прав (ui.accessrights.v2) при сохранении.
 *
 * Роль доступа: id ('0' или пусто — новая), title, accessCodes {код => тип},
 * accessRights [{id: право, value: '1'|'0'}]. Назначать роль можно отделу
 * вместе с подотделами (DR<id>) и сотруднику (U<id>); другие коды селектора
 * отклоняются ошибкой — молча выкинуть их значило бы сохранить не то, что
 * видел администратор. Так же разбирает форму shef.toolsai (Access\RoleForm).
 *
 * Чистый класс — без ядра (access_test.php).
 */
final class RoleForm
{
	public const TITLE_MAX = 255;
	public const CODE_PATTERN = '/^(U|DR)[1-9]\d{0,9}$/';

	/**
	 * @return array{roles: list<array{id: int, title: string, codes: list<string>, actions: list<string>}>, deleted: list<int>, errors: list<string>}
	 */
	public static function parse(mixed $userGroups, mixed $deletedUserGroups): array
	{
		$roles = [];
		$errors = [];
		foreach(is_array($userGroups) ? $userGroups : [] as $group)
		{
			if(!is_array($group))
			{
				continue;
			}
			$title = trim(is_string($group['title'] ?? null) ? $group['title'] : '');
			if($title === '')
			{
				$errors[] = 'У роли должно быть название';
				continue;
			}
			$title = mb_substr($title, 0, self::TITLE_MAX);

			$codes = [];
			foreach(array_keys(is_array($group['accessCodes'] ?? null) ? $group['accessCodes'] : []) as $code)
			{
				$code = (string)$code;
				if(preg_match(self::CODE_PATTERN, $code))
				{
					$codes[] = $code;
				}
				else
				{
					$errors[] = 'Роль «'.$title.'»: назначить можно только отдел целиком (вместе с подотделами) или сотрудника, а выбрано: '.$code;
				}
			}

			$actions = [];
			foreach(is_array($group['accessRights'] ?? null) ? $group['accessRights'] : [] as $right)
			{
				if(is_array($right) && (string)($right['value'] ?? '') === '1')
				{
					$actions[] = (string)($right['id'] ?? '');
				}
			}

			$roles[] = [
				'id' => static::id($group['id'] ?? null),
				'title' => $title,
				'codes' => array_values(array_unique($codes)),
				'actions' => Actions::normalize($actions),
			];
		}

		$deleted = [];
		foreach(is_array($deletedUserGroups) ? $deletedUserGroups : [] as $id)
		{
			$id = static::id($id);
			if($id > 0)
			{
				$deleted[] = $id;
			}
		}

		return ['roles' => $roles, 'deleted' => array_values(array_unique($deleted)), 'errors' => $errors];
	}

	/** Идентификатор роли: целое больше нуля, иначе 0 — новая роль. */
	private static function id(mixed $value): int
	{
		return (is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', $value))) ? max(0, (int)$value) : 0;
	}
}
