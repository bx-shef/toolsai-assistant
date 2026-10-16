<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Выбранная роль помощника — на сотрудника и чат.
 *
 * CHAT_KEY — «general» (общий чат) или «record:<entityTypeId>:<id>» (чат
 * по записи CRM), см. Chat\ChatKey. Строка одна на пару USER_ID + CHAT_KEY
 * (уникальный индекс). Право на роль это не даёт: сервер проверяет его на
 * каждом ответе (Chat\AnswerService).
 */
class RoleChoiceTable extends DataManager
{
	/** Уникальный индекс: на сотрудника и чат — одна роль. */
	public const INDEXES = ['UX_SHEF_TAIA_CHOICE' => ['columns' => ['USER_ID', 'CHAT_KEY'], 'unique' => true]];

	public static function getTableName(): string
	{
		return 'shef_toolsaiassistant_role_choice';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new IntegerField('USER_ID'))->configureRequired(),
			(new StringField('CHAT_KEY'))->configureRequired()->configureSize(64),
			(new StringField('ROLE_CODE'))->configureRequired()->configureSize(150),
			(new DatetimeField('CHANGED_AT'))->configureDefaultValueNow(),
		];
	}
}
