<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Что открыто у сотрудника рядом с чатом (1.13.0): страница основного окна
 * или верхнего слайдера. Окно присылает только путь (`page`), разбирает его
 * сервер — тексты для модели собираются здесь, из браузера приходят лишь
 * вид страницы и числа.
 *
 * Виды: карточка CRM, список или канбан CRM (тип и воронка), задача, список
 * задач и календарь (сотрудника или группы). Прочее — путь как есть, без подсказок.
 *
 * Открытая страница и запись чата (Record — чат открыт из записи) могут
 * расходиться: чат по сделке 5, а в слайдере — сделка 9 или задача. Тогда модель
 * видит обе: что открыто и по какой записи чат; подсказки агенту — по обеим.
 *
 * Для режима «Агент» — подсказки, каким инструментом MCP (toolsai-mcp) взять
 * подробности открытого: только для инструментов, которые есть у сотрудника.
 *
 * Чистый класс — без ядра (pagecontext_test.php).
 */
final class PageContext
{
	public const PATH_MAX = 300;
	public const RECORD = 'crm_record';
	public const CRM_LIST = 'crm_list';
	public const TASK = 'task';
	public const TASKS = 'tasks';
	public const CALENDAR = 'calendar';
	public const OTHER = 'other';

	/** Тип CRM по слову адреса (как DETAIL_TYPES в chat.js). */
	private const TYPES = ['lead' => 1, 'deal' => 2, 'contact' => 3, 'company' => 4, 'quote' => 7];
	/** Имя сущности для crm-инструментов toolsai-mcp (crmEntity); прочие — числом. */
	private const ENTITY = [1 => 'lead', 2 => 'deal', 3 => 'contact', 4 => 'company'];
	private const NOUN = [1 => 'лид', 2 => 'сделка', 3 => 'контакт', 4 => 'компания', 7 => 'предложение'];
	/** Без стадий: crm_funnel отвечает ошибкой, crm_stages — пусто (toolsai-mcp). */
	private const NO_STAGES = [3, 4];

	public function __construct(
		public readonly string $kind,
		public readonly string $path,
		public readonly int $entityTypeId = 0,
		public readonly int $id = 0,
		public readonly int $categoryId = -1,
		/** Канбан (true) или список. */
		public readonly bool $kanban = false,
		/** Владелец списка задач или календаря: сотрудник (ownerUserId) или группа (ownerGroupId). */
		public readonly int $ownerUserId = 0,
		public readonly int $ownerGroupId = 0,
	)
	{
	}

	/** Из пути страницы; не путь своего портала — null. Запрос и якорь отбрасываются. */
	public static function fromPath(mixed $raw): ?self
	{
		if(!is_string($raw))
		{
			return null;
		}
		$path = (string)preg_replace('/[?#].*$/s', '', trim($raw));
		if($path === '' || mb_strlen($path) > self::PATH_MAX || !ContextBuilder::isLocalUrl($path))
		{
			return null;
		}
		if(!str_ends_with($path, '/'))
		{
			$path .= '/';
		}

		if(preg_match('~^/crm/(?:type/(\d{1,10})|([a-z]+))/details/(\d{1,10})/~', $path, $m))
		{
			$type = $m[1] !== '' ? (int)$m[1] : (self::TYPES[$m[2]] ?? 0);
			$id = (int)$m[3];

			return $type > 0 && $id > 0 ? new self(self::RECORD, $path, $type, $id) : new self(self::OTHER, $path);
		}
		// /crm/deal/list/, /crm/deal/kanban/category/3/, /crm/deal/category/3/ (список воронки), /crm/type/1032/list/category/0/
		if(preg_match('~^/crm/(?:type/(\d{1,10})|([a-z]+))/(?:(list|kanban)/(?:category/(\d{1,10})/)?|category/(\d{1,10})/)~', $path, $m))
		{
			$type = $m[1] !== '' ? (int)$m[1] : (self::TYPES[$m[2]] ?? 0);
			$category = ($m[4] ?? '') !== '' ? (int)$m[4] : (($m[5] ?? '') !== '' ? (int)$m[5] : -1);

			return $type > 0
				? new self(self::CRM_LIST, $path, $type, 0, $category, ($m[3] ?? '') === 'kanban')
				: new self(self::OTHER, $path);
		}
		if(preg_match('~/tasks/task/(?:view|edit)/(\d{1,10})/~', $path, $m) && (int)$m[1] > 0)
		{
			return new self(self::TASK, $path, 0, (int)$m[1]);
		}
		if(preg_match('~^/(?:company/personal/user/(\d{1,10})|workgroups/group/(\d{1,10}))/(tasks|calendar)/~', $path, $m))
		{
			return new self($m[3] === 'tasks' ? self::TASKS : self::CALENDAR, $path, 0, 0, -1, false, (int)$m[1], (int)($m[2] ?? 0));
		}

		return new self(self::OTHER, $path);
	}

	/**
	 * Строка «что открыто» для модели; сказать нечего (ни пути, ни записи) — null.
	 *
	 * @param ?Record $chatRecord запись чата (чат открыт из неё)
	 * @param ?array{title?: mixed, noun?: mixed} $record её данные с сервера (RecordService)
	 */
	public static function line(?self $page, ?Record $chatRecord, ?array $record = null): ?string
	{
		if($chatRecord === null)
		{
			return $page?->text(false);
		}
		$recordLine = 'карточка CRM: '.static::recordName($chatRecord, $record).' (entityTypeId='.$chatRecord->entityTypeId.', id='.$chatRecord->id.')';
		if($page === null || $page->isRecord($chatRecord))
		{
			return 'Открыта '.$recordLine.'.';
		}

		return $page->text(true).' Чат ведётся по записи — '.$recordLine.'; её лента ниже.';
	}

	/**
	 * Подсказки агенту: каким инструментом взять подробности открытого и записи
	 * чата. Только инструменты из $tools (имя => что угодно).
	 *
	 * @param array<string, mixed> $tools
	 * @return list<string>
	 */
	public static function hints(?self $page, ?Record $chatRecord, array $tools): array
	{
		$hints = [];
		$pageRecord = $page !== null && $page->kind === self::RECORD ? new Record($page->entityTypeId, $page->id) : null;
		if($page !== null)
		{
			$hints = $pageRecord !== null ? static::recordHints($pageRecord, $tools) : $page->pageHints($tools);
		}
		if($chatRecord !== null && ($pageRecord === null || $pageRecord->entityTypeId !== $chatRecord->entityTypeId || $pageRecord->id !== $chatRecord->id))
		{
			$hints = array_merge($hints, static::recordHints($chatRecord, $tools));
		}
		$hints = array_values(array_unique($hints));

		return $hints === [] ? [] : array_merge(['Подробности открытого — инструментами (сначала ответь из того, что уже есть выше; инструмент — когда данных не хватает):'], $hints);
	}

	private function isRecord(Record $record): bool
	{
		return $this->kind === self::RECORD && $this->entityTypeId === $record->entityTypeId && $this->id === $record->id;
	}

	/** @param ?array{title?: mixed, noun?: mixed} $record */
	private static function recordName(Record $openRecord, ?array $record): string
	{
		$noun = trim(is_string($record['noun'] ?? null) ? $record['noun'] : '');
		$title = trim(is_string($record['title'] ?? null) ? $record['title'] : '');

		return ($noun !== '' ? $noun : (self::NOUN[$openRecord->entityTypeId] ?? 'запись CRM')).($title !== '' ? ' «'.mb_substr($title, 0, 200).'»' : '');
	}

	/** @param bool $withChatRecord у чата своя запись — у открытой карточки её ленты нет */
	private function text(bool $withChatRecord): string
	{
		$owner = $this->ownerGroupId > 0 ? ' группы (groupId='.$this->ownerGroupId.')' : ($this->ownerUserId > 0 ? ' сотрудника (userId='.$this->ownerUserId.')' : '');

		return match($this->kind)
		{
			self::RECORD => 'Открыта карточка CRM: '.(self::NOUN[$this->entityTypeId] ?? 'запись').' (entityTypeId='.$this->entityTypeId.', id='.$this->id.')'.($withChatRecord ? ', её ленты ниже нет.' : '; чат открыт не из неё, поэтому её ленты ниже нет.'),
			self::CRM_LIST => 'Открыт '.($this->kanban ? 'канбан' : 'список').' CRM: '.(self::NOUN[$this->entityTypeId] ?? 'смарт-процесс').' (entityTypeId='.$this->entityTypeId.($this->categoryId >= 0 ? ', воронка categoryId='.$this->categoryId : '').'). Какие записи и фильтры видит сотрудник, неизвестно — уточни или найди инструментами.',
			self::TASK => 'Открыта задача (id='.$this->id.').',
			self::TASKS => 'Открыт список задач'.$owner.'.',
			self::CALENDAR => 'Открыт календарь'.$owner.'.',
			default => 'Открыта страница портала '.$this->path.'.',
		};
	}

	/**
	 * @param array<string, mixed> $tools
	 * @return list<string>
	 */
	private static function recordHints(Record $record, array $tools): array
	{
		$out = [];
		$entity = json_encode(self::ENTITY[$record->entityTypeId] ?? $record->entityTypeId);
		static::add($out, $tools, 'crm_item_get', '{"entity": '.$entity.', "id": '.$record->id.'} — все поля записи (стадия, сумма, ответственный, свои поля);');
		static::add($out, $tools, 'crm_fields', '{"entity": '.$entity.'} — названия полей, если коды непонятны;');
		if(!in_array($record->entityTypeId, self::NO_STAGES, true))
		{
			static::add($out, $tools, 'crm_stages', '{"entity": '.$entity.'} — названия стадий;');
		}
		if(isset(self::ENTITY[$record->entityTypeId]))
		{
			static::add($out, $tools, 'crm_activity_list', '{"ownerType": '.$entity.', "ownerId": '.$record->id.'} — дела и звонки записи, если ленты выше не хватает;');
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $tools
	 * @return list<string>
	 */
	private function pageHints(array $tools): array
	{
		$out = [];
		$entity = json_encode(self::ENTITY[$this->entityTypeId] ?? $this->entityTypeId);
		$category = $this->categoryId >= 0 ? $this->categoryId : null;
		switch($this->kind)
		{
			case self::CRM_LIST:
				static::add($out, $tools, 'crm_item_list', '{"entity": '.$entity.($category !== null ? ', "filter": {"categoryId": '.$category.'}' : '').'} — записи этого списка (фильтр — по схеме инструмента);');
				if(!in_array($this->entityTypeId, self::NO_STAGES, true))
				{
					static::add($out, $tools, 'crm_funnel', '{"entity": '.$entity.($category !== null ? ', "categoryId": '.$category : '').'} — воронка по стадиям;');
					static::add($out, $tools, 'crm_stages', '{"entity": '.$entity.($category !== null ? ', "categoryId": '.$category : '').'} — стадии;');
				}
				break;
			case self::TASK:
				static::add($out, $tools, 'task_get', '{"id": '.$this->id.'} — открытая задача: описание, сроки, участники;');
				static::add($out, $tools, 'task_comments', '{"taskId": '.$this->id.'} — комментарии задачи;');
				static::add($out, $tools, 'task_checklist', '{"taskId": '.$this->id.'} — чек-лист задачи;');
				break;
			case self::TASKS:
				$filter = $this->ownerGroupId > 0 ? '{"filter": {"GROUP_ID": '.$this->ownerGroupId.'}}' : ($this->ownerUserId > 0 ? '{"filter": {"RESPONSIBLE_ID": '.$this->ownerUserId.'}}' : '{}');
				static::add($out, $tools, 'task_list', $filter.' — задачи этого списка;');
				break;
			case self::CALENDAR:
				$owner = $this->ownerGroupId > 0 ? ', "type": "group", "ownerId": '.$this->ownerGroupId : ($this->ownerUserId > 0 ? ', "type": "user", "ownerId": '.$this->ownerUserId : '');
				static::add($out, $tools, 'calendar_events', '{"from": "ГГГГ-ММ-ДД", "to": "ГГГГ-ММ-ДД"'.$owner.'} — события этого календаря;');
				break;
		}

		return $out;
	}

	/** @param array<string, mixed> $tools */
	private static function add(array &$out, array $tools, string $tool, string $text): void
	{
		if(isset($tools[$tool]))
		{
			$out[] = '- '.$tool.' '.$text;
		}
	}
}
