<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Agent\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Вызовы агента, ждущие «Выполнить» (1.9.0). Своя таблица, а не опция
 * сотрудника: личные опции сотрудник пишет сам штатным ajax ядра — вызов
 * можно было бы подложить. PAYLOAD — JSON {tool, title, arguments, trace};
 * забирается один раз (DELETE по ID, Agent\PendingStore).
 */
class PendingTable extends DataManager
{
	public const INDEXES = ['UX_SHEF_TAIA_PENDING' => ['columns' => ['USER_ID', 'CODE'], 'unique' => true]];

	public static function getTableName(): string
	{
		return 'shef_toolsaiassistant_agent_pending';
	}

	/** Поля: ID, USER_ID, CODE (16 hex), PAYLOAD, CREATED_AT (unix). */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new IntegerField('USER_ID'))->configureRequired(),
			(new StringField('CODE'))->configureRequired()->configureSize(32),
			(new TextField('PAYLOAD'))->configureRequired(),
			(new IntegerField('CREATED_AT'))->configureRequired(),
		];
	}
}
