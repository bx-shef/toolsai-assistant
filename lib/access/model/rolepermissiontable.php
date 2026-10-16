<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Access\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Право роли доступа: строка — право есть (Access\Actions: chat.use или
 * role:<код промпта>). Код промпта до 80 символов плюс модуль — отсюда 150.
 */
class RolePermissionTable extends DataManager
{
	/** Уникальный индекс: право у роли одно. */
	public const INDEXES = ['UX_SHEF_TAIA_ROLE_PERM' => ['columns' => ['ROLE_ID', 'ACTION'], 'unique' => true]];

	public static function getTableName(): string
	{
		return 'shef_toolsaiassistant_role_permission';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new IntegerField('ROLE_ID'))->configureRequired(),
			(new StringField('ACTION'))->configureRequired()->configureSize(150),
		];
	}
}
