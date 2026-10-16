<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Access\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Кому назначена роль доступа: код — отдел с подотделами DR<id> или
 * сотрудник U<id> (Access\RoleForm::CODE_PATTERN).
 */
class RoleAccessTable extends DataManager
{
	/** Уникальный индекс: код у роли один. */
	public const INDEXES = ['UX_SHEF_TAIA_ROLE_ACCESS' => ['columns' => ['ROLE_ID', 'ACCESS_CODE'], 'unique' => true]];

	/** Имя таблицы в базе. */
	public static function getTableName(): string
	{
		return 'shef_toolsaiassistant_role_access';
	}

	/** Поля: ID, ROLE_ID, ACCESS_CODE. */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new IntegerField('ROLE_ID'))->configureRequired(),
			(new StringField('ACCESS_CODE'))->configureRequired()->configureSize(100),
		];
	}
}
