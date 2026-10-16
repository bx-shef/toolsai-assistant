<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Access;

use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\UI\AccessRights\DataProvider;
use Bitrix\UI\AccessRights\V2\Options;
use Bitrix\UI\AccessRights\V2\Options\AdditionalMemberOptions;
use Bitrix\UI\AccessRights\V2\Options\RightSection;
use Bitrix\UI\AccessRights\V2\Options\RightSection\RightItem;
use Bitrix\UI\AccessRights\V2\Options\UserGroup;
use Bitrix\UI\AccessRights\V2\Options\UserGroup\Member;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Страница «Права доступа» на штатном ui.accessrights.v2: роли доступа ×
 * права, кому роль назначена. Как права генератора документов
 * (documentgenerator.settings.perms) и права shef.toolsai (Access\RightsPage).
 *
 * Права — два раздела: «Чат ИИ-помощника» (chat.use) и «Роли помощника» —
 * переключатель на каждую включённую роль реестра промптов shef.toolsai.
 * Роль, которую потом выключили в «Библиотеке промптов», со страницы
 * пропадает, а её право в базе остаётся — включили снова, и права вернулись.
 *
 * Сохраняет JS: BX.ajax.runComponentAction(RIGHTS_COMPONENT, 'save', {mode:
 * 'ajax'}) — ядро ищет ajax.php рядом с компонентом
 * (install/components/shef/toolsaiassistant.rights/ajax.php), он ведёт в
 * Controller\Access.
 */
final class RightsPage
{
	public const CONTAINER_ID = 'shef-toolsaiassistant-access-rights';
	public const ACTION_SAVE = 'save';

	/**
	 * @param list<array{code: string, title: string, description: string}> $promptRoles роли реестра промптов
	 */
	public static function options(array $promptRoles): Options
	{
		return (new Options(Constants::RIGHTS_COMPONENT, self::CONTAINER_ID))
			->setModuleId(Constants::MODULE_ID)
			->setActionSave(self::ACTION_SAVE)
			->setMode('ajax')
			->setBodyType('data')
			->setIsSaveOnlyChangedRights(false)
			// Отделы (DR) и сотрудники; группы, проекты и команды — выключены,
			// остальное сервер отклоняет (RoleForm)
			->configureAdditionalMembersParams(
				/** Выбор участников: только отделы с подотделами и сотрудники. */
				static function(AdditionalMemberOptions $options): void
			{
				$options
					->setAddUserGroupsProviderTab(false)
					->setAddProjectsProviderTab(false)
					->setAddStructureTeamsProviderTab(false)
					->setAddStructureRolesProviderTab(false)
					->setUseStructureDepartmentsProviderTab(false);
			})
			->setAccessRights(static::sections($promptRoles))
			->setUserGroups(static::userGroups(RoleStore::load()));
	}

	/**
	 * @param list<array{code: string, title: string, description: string}> $promptRoles
	 * @return list<RightSection>
	 */
	public static function sections(array $promptRoles): array
	{
		$chat = [];
		foreach(Actions::getList() as $code => $action)
		{
			$chat[] = static::toggler($code, $action['title'], $action['hint']);
		}
		$roles = [];
		foreach($promptRoles as $role)
		{
			$roles[] = static::toggler(Actions::roleRight($role['code']), $role['title'], $role['description'] !== '' ? $role['description'] : $role['code']);
		}

		return [
			(new RightSection(Actions::GROUPS[Actions::GROUP_CHAT]))->setCode(Actions::GROUP_CHAT)->setRightItems($chat),
			(new RightSection(Actions::GROUPS[Actions::GROUP_ROLES]))->setCode(Actions::GROUP_ROLES)->setRightItems($roles),
		];
	}

	/**
	 * @param array<int, array{id: int, title: string, codes: list<string>, actions: list<string>}> $roles
	 * @return list<UserGroup>
	 */
	public static function userGroups(array $roles): array
	{
		$provider = new DataProvider();
		$groups = [];
		foreach($roles as $role)
		{
			$group = new UserGroup((string)$role['id'], $role['title']);
			foreach($role['codes'] as $code)
			{
				if(!AccessCode::isValid($code))
				{
					continue;
				}
				try
				{
					$parsed = new AccessCode($code);
					$entity = $provider->getEntity($parsed->getEntityType(), $parsed->getEntityId());
				}
				catch(\Throwable)
				{
					continue;
				}
				$group->addMember($code, Member::fromAccessRightEntity($entity));
			}
			foreach($role['actions'] as $action)
			{
				$group->addAccessRight(new Member\Access($action, '1'));
			}
			$groups[] = $group;
		}

		return $groups;
	}

	/** @return list<array> роли в форме ответа на сохранение (USER_GROUPS) */
	public static function userGroupsArray(): array
	{
		return array_map(static fn(UserGroup $group): array => $group->toArray(), static::userGroups(RoleStore::load()));
	}

	/** Переключатель «да/нет» — как у прав shef.toolsai. */
	private static function toggler(string $code, string $title, string $hint): RightItem
	{
		return (new RightItem($code, $title, RightItem\Type::Toggler))
			->setHint($hint)
			->setMinValue('0')
			->setMaxValue('1')
			->setEmptyValue('0')
			->setDefaultValue('0')
			->setNothingSelectedValue('0');
	}
}
