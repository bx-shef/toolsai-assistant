<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

/**
 * Фильтр страницы «Оценки ответов» (1.5.0) — штатный main.ui.filter над
 * штатным гридом main.ui.grid, как «ИИ: отчёт по звонкам» shef.toolsai
 * (Calls\UiFilter там).
 *
 *   fields()    — поля фильтра: период, оценка, статус, роль, сотрудник, чат;
 *   values()    — значения фильтра (Filter\Options::getFilter) → свой разбор;
 *   orm()       — свой разбор → фильтр getList() таблицы оценок;
 *   sort()      — сортировка грида (Grid\Options::getSorting) → order getList().
 *
 * Чистый класс — без ядра (feedback_test.php).
 */
final class GridFilter
{
	public const FILTER_ID = 'shef_toolsaiassistant_feedback_filter';
	public const GRID_ID = 'shef_toolsaiassistant_feedback_grid';

	/** Чат: общий, справка, любая запись, запись типа (record:<entityTypeId>). */
	public const CHATS = [
		'general' => 'Общий чат',
		'docs' => 'Чат справки',
		'record' => 'Любая запись CRM',
		'record:2' => 'Сделки',
		'record:1' => 'Лиды',
		'record:3' => 'Контакты',
		'record:4' => 'Компании',
		'record:7' => 'Предложения',
	];

	/** Колонки, по которым грид сортирует (id колонки => поле таблицы). */
	public const SORTABLE = ['CREATED_AT' => 'CREATED_AT', 'RATING' => 'RATING', 'STATUS' => 'STATUS', 'ROLE' => 'ROLE_CODE', 'USER' => 'USER_ID'];

	/**
	 * Поля фильтра.
	 *
	 * @param array<string, string> $roles код роли => название
	 */
	public static function fields(array $roles): array
	{
		return [
			['id' => 'PERIOD', 'name' => 'Период', 'type' => 'date', 'default' => true],
			['id' => 'RATING', 'name' => 'Оценка', 'type' => 'list', 'default' => true, 'items' => ['up' => Rating::title(Rating::UP), 'down' => Rating::title(Rating::DOWN)], 'params' => ['multiple' => 'N']],
			['id' => 'STATUS', 'name' => 'Статус', 'type' => 'list', 'default' => true, 'items' => Review::STATUSES, 'params' => ['multiple' => 'Y']],
			['id' => 'ROLE', 'name' => 'Роль', 'type' => 'list', 'default' => true, 'items' => $roles, 'params' => ['multiple' => 'Y']],
			['id' => 'USER', 'name' => 'Сотрудник', 'type' => 'entity_selector', 'default' => true, 'partial' => true, 'params' => [
				'multiple' => 'N',
				'dialogOptions' => ['context' => 'shef_toolsaiassistant_feedback_user', 'entities' => [['id' => 'user', 'options' => ['inviteEmployeeLink' => false, 'intranetUsersOnly' => true]]]],
			]],
			['id' => 'CHAT', 'name' => 'Чат / запись', 'type' => 'list', 'items' => self::CHATS, 'params' => ['multiple' => 'N']],
			['id' => 'RECORD_ID', 'name' => 'Номер записи CRM', 'type' => 'number'],
		];
	}

	/**
	 * Значения фильтра → разбор.
	 *
	 * @return array{from?: string, to?: string, rating?: int, status?: list<string>, role?: list<string>, user?: int, chat?: string, recordId?: int}
	 *         from/to — Y-m-d, включительно
	 */
	public static function values(array $filter): array
	{
		$out = [];
		if(($from = static::date($filter['PERIOD_from'] ?? null)) !== null)
		{
			$out['from'] = $from;
		}
		if(($to = static::date($filter['PERIOD_to'] ?? null)) !== null)
		{
			$out['to'] = $to;
		}
		$rating = is_string($filter['RATING'] ?? null) ? FeedbackPage::ratingFilter($filter['RATING']) : null;
		if($rating !== null)
		{
			$out['rating'] = $rating;
		}
		$status = array_values(array_filter(array_map([Review::class, 'status'], (array)($filter['STATUS'] ?? []))));
		if($status !== [])
		{
			$out['status'] = $status;
		}
		$roles = array_values(array_filter((array)($filter['ROLE'] ?? []), static fn(mixed $code): bool => is_string($code) && preg_match(\Shef\ToolsAiAssistant\Access\Actions::PROMPT_CODE_PATTERN, $code) === 1));
		if($roles !== [])
		{
			$out['role'] = $roles;
		}
		$user = $filter['USER'] ?? null;
		$user = is_array($user) ? reset($user) : $user;
		$user = is_scalar($user) ? (string)preg_replace('/^U/', '', (string)$user) : '';
		if(ctype_digit($user) && (int)$user > 0)
		{
			$out['user'] = (int)$user;
		}
		if(is_string($filter['CHAT'] ?? null) && isset(self::CHATS[$filter['CHAT']]))
		{
			$out['chat'] = $filter['CHAT'];
		}
		$recordId = $filter['RECORD_ID_from'] ?? $filter['RECORD_ID'] ?? null;
		if(is_numeric($recordId) && (int)$recordId > 0)
		{
			$out['recordId'] = (int)$recordId;
		}

		return $out;
	}

	/**
	 * Разбор → фильтр getList() (ORM). Даты — строками Y-m-d H:i:s: их
	 * переводит в DateTime ядра FeedbackStore.
	 *
	 * @param array<string, mixed> $values values()
	 * @param list<string> $skip какие части не брать (сводка по ролям — без role и rating)
	 * @return array<string, mixed>
	 */
	public static function orm(array $values, array $skip = []): array
	{
		$values = array_diff_key($values, array_flip($skip));
		$orm = [];
		if(isset($values['from']))
		{
			$orm['>=CREATED_AT'] = $values['from'].' 00:00:00';
		}
		if(isset($values['to']))
		{
			$orm['<=CREATED_AT'] = $values['to'].' 23:59:59';
		}
		if(isset($values['rating']))
		{
			$orm['=RATING'] = (int)$values['rating'];
		}
		if(!empty($values['status']))
		{
			$orm['@STATUS'] = array_values($values['status']);
		}
		if(!empty($values['role']))
		{
			$orm['@ROLE_CODE'] = array_values($values['role']);
		}
		if(isset($values['user']))
		{
			$orm['=USER_ID'] = (int)$values['user'];
		}
		$chat = (string)($values['chat'] ?? '');
		$recordId = isset($values['recordId']) ? (int)$values['recordId'] : 0;
		if($chat === 'general' || $chat === 'docs')
		{
			$orm['=CHAT_KEY'] = $chat;
		}
		elseif($recordId > 0)
		{
			// Номер записи: тип — из «Чат / запись», иначе любой
			$orm['%=CHAT_KEY'] = ($chat !== '' && $chat !== 'record' ? $chat : 'record:%').':'.$recordId;
		}
		elseif($chat !== '')
		{
			$orm['%=CHAT_KEY'] = $chat === 'record' ? 'record:%' : $chat.':%';
		}

		return $orm;
	}

	/**
	 * Сортировка грида → order getList(); чужая колонка — от свежих.
	 *
	 * @param array<string, string> $sort ['CREATED_AT' => 'desc']
	 * @return array<string, string>
	 */
	public static function sort(array $sort): array
	{
		$column = (string)array_key_first($sort);
		$field = self::SORTABLE[$column] ?? null;
		if($field === null)
		{
			return ['ID' => 'DESC'];
		}
		$direction = strtolower((string)$sort[$column]) === 'asc' ? 'ASC' : 'DESC';

		return [$field => $direction] + ($field !== 'CREATED_AT' ? ['ID' => 'DESC'] : []);
	}

	private static function date(mixed $value): ?string
	{
		if(!is_string($value) || trim($value) === '')
		{
			return null;
		}
		foreach(['d.m.Y H:i:s', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d', 'm/d/Y h:i:s a', 'm/d/Y'] as $format)
		{
			$date = \DateTimeImmutable::createFromFormat('!'.$format, trim($value));
			if($date !== false)
			{
				return $date->format('Y-m-d');
			}
		}

		return null;
	}
}
