<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Mcp\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Подключение сотрудника к MCP-серверу (1.10.0): токены — зашифрованы
 * (Mcp\TokenCrypto), SERVER — адрес сервера, для которого выданы: сменили
 * адрес в настройках — подключение не годится. Строка на сотрудника.
 */
class McpTokenTable extends DataManager
{
	public const INDEXES = ['UX_SHEF_TAIA_MCP_USER' => ['columns' => ['USER_ID'], 'unique' => true]];

	public static function getTableName(): string
	{
		return 'shef_toolsaiassistant_mcp_token';
	}

	/** Поля: ID, USER_ID, SERVER, CLIENT_ID, ACCESS_TOKEN, REFRESH_TOKEN, EXPIRES_AT (unix), CHANGED_AT. */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new IntegerField('USER_ID'))->configureRequired(),
			(new StringField('SERVER'))->configureRequired()->configureSize(300),
			(new StringField('CLIENT_ID'))->configureRequired()->configureSize(200),
			(new TextField('ACCESS_TOKEN'))->configureRequired(),
			(new TextField('REFRESH_TOKEN'))->configureRequired(),
			(new IntegerField('EXPIRES_AT'))->configureRequired(),
			(new DatetimeField('CHANGED_AT'))->configureDefaultValueNow(),
		];
	}
}
