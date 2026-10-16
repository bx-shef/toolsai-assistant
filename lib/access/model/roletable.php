<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Access\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Роль доступа к модулю: название. Права роли — RolePermissionTable, кому
 * назначена — RoleAccessTable.
 */
class RoleTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'shef_toolsaiassistant_role';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new StringField('NAME'))->configureRequired()->configureSize(255),
		];
	}
}
