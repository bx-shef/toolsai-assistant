<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Оценки ответов помощника «полезно / не помогло» (1.1.0).
 *
 * Строка — одна оценка: кто, в какой роли, по какому чату (CHAT_KEY —
 * Chat\ChatKey: «general» или «record:<entityTypeId>:<id>»), вопрос и ответ
 * кратко (Feedback\Rating), оценка 1 / -1, комментарий, время. Список — на
 * странице «Оценки ответов» (Feedback\FeedbackPage), право feedback.view.
 *
 * Разбор (1.4.0, право feedback.edit): STATUS — Feedback\Review (new /
 * reviewed / eval / rejected), EXPECTED — «что должно было быть» (факты по
 * строке, Feedback\Review::facts()), REVIEWED_BY и REVIEWED_AT — кто и когда
 * разобрал. Колонки на портале добавляет Feedback\FeedbackStore::install()
 * (Main\Maintenance при смене версии, установщик там не запускается).
 */
class FeedbackTable extends DataManager
{
	/** Индекс по времени: список идёт от свежих. */
	public const INDEXES = [
		'IX_SHEF_TAIA_FEEDBACK_AT' => ['columns' => ['CREATED_AT'], 'unique' => false],
		'IX_SHEF_TAIA_FEEDBACK_ST' => ['columns' => ['STATUS'], 'unique' => false],
	];

	/** Колонки разбора (1.4.0): их нет в таблицах, созданных до 1.4.0. */
	public const REVIEW_COLUMNS = ['STATUS', 'EXPECTED', 'REVIEWED_BY', 'REVIEWED_AT'];

	/** Имя таблицы в базе. */
	public static function getTableName(): string
	{
		return 'shef_toolsaiassistant_feedback';
	}

	/** Поля: ID, CREATED_AT, USER_ID, ROLE_CODE, CHAT_KEY, QUESTION, ANSWER, RATING, COMMENT; разбор — STATUS, EXPECTED, REVIEWED_BY, REVIEWED_AT. */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new DatetimeField('CREATED_AT'))->configureRequired()->configureDefaultValueNow(),
			(new IntegerField('USER_ID'))->configureRequired(),
			(new StringField('ROLE_CODE'))->configureRequired()->configureSize(150),
			(new StringField('CHAT_KEY'))->configureRequired()->configureSize(64),
			(new TextField('QUESTION'))->configureRequired(),
			(new TextField('ANSWER'))->configureRequired(),
			(new IntegerField('RATING'))->configureRequired(),
			(new TextField('COMMENT'))->configureNullable(),
			(new StringField('STATUS'))->configureRequired()->configureSize(16)->configureDefaultValue(\Shef\ToolsAiAssistant\Feedback\Review::NEW),
			(new TextField('EXPECTED'))->configureNullable(),
			(new IntegerField('REVIEWED_BY'))->configureNullable(),
			(new DatetimeField('REVIEWED_AT'))->configureNullable(),
		];
	}
}
