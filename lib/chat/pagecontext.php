<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Что открыто у сотрудника рядом с чатом (1.13.0): страница основного окна
 * или верхнего слайдера. Окно присылает только путь (`page`), разбирает его
 * сервер — тексты для модели собираются здесь, из браузера приходят лишь
 * вид страницы и числа.
 *
 * Виды: карточка CRM (запись — Record, её текст — RecordService), список или
 * канбан CRM (тип и воронка), задача, список задач, календарь. Прочее — путь
 * как есть, без подсказок.
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

	public function __construct(
		public readonly string $kind,
		public readonly string $path,
		public readonly int $entityTypeId = 0,
		public readonly int $id = 0,
		public readonly int $categoryId = -1,
		/** Канбан (true) или список. */
		public readonly bool $kanban = false,
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
		if(preg_match('~^/crm/(?:type/(\d{1,10})|([a-z]+))/(list|kanban)/(?:category/(\d{1,10})/)?~', $path, $m))
		{
			$type = $m[1] !== '' ? (int)$m[1] : (self::TYPES[$m[2]] ?? 0);

			return $type > 0
				? new self(self::CRM_LIST, $path, $type, 0, isset($m[4]) && $m[4] !== '' ? (int)$m[4] : -1, $m[3] === 'kanban')
				: new self(self::OTHER, $path);
		}
		if(preg_match('~/tasks/task/(?:view|edit)/(\d{1,10})/~', $path, $m) && (int)$m[1] > 0)
		{
			return new self(self::TASK, $path, 0, (int)$m[1]);
		}
		if(preg_match('~^/(?:company/personal/user/\d+|workgroups/group/\d+)/tasks/~', $path))
		{
			return new self(self::TASKS, $path);
		}
		if(preg_match('~^/(?:company/personal/user/\d+|workgroups/group/\d+)/calendar/~', $path))
		{
			return new self(self::CALENDAR, $path);
		}

		return new self(self::OTHER, $path);
	}

	/**
	 * Строка «что открыто» для модели.
	 *
	 * @param ?array{title: string, noun: string} $record запись CRM с сервера (RecordService), если открыта карточка
	 */
	public function describe(?Record $openRecord = null, ?array $record = null): string
	{
		return self::line($this, $openRecord, $record);
	}

	/**
	 * То же, когда пути нет (старое окно): по записи чата или null — сказать нечего.
	 *
	 * @param ?array{title: string, noun: string} $record
	 */
	public static function line(?self $page, ?Record $openRecord, ?array $record = null): ?string
	{
		if($page === null && $openRecord === null)
		{
			return null;
		}

		return ($page ?? new self(self::RECORD, ''))->text($openRecord, $record);
	}

	/** @param ?array{title: string, noun: string} $record */
	private function text(?Record $openRecord, ?array $record): string
	{
		if($openRecord !== null)
		{
			$noun = trim(is_string($record['noun'] ?? null) ? $record['noun'] : '');
			$title = trim(is_string($record['title'] ?? null) ? $record['title'] : '');
			$noun = $noun !== '' ? $noun : (self::NOUN[$openRecord->entityTypeId] ?? 'запись CRM');
			$title = $title !== '' ? ' «'.mb_substr($title, 0, 200).'»' : '';

			return 'Открыта карточка CRM: '.$noun.$title.' (entityTypeId='.$openRecord->entityTypeId.', id='.$openRecord->id.').';
		}

		return match($this->kind)
		{
			self::CRM_LIST => 'Открыт '.($this->kanban ? 'канбан' : 'список').' CRM: '.(self::NOUN[$this->entityTypeId] ?? 'смарт-процесс').' (entityTypeId='.$this->entityTypeId.($this->categoryId >= 0 ? ', воронка categoryId='.$this->categoryId : '').'). Какие записи и фильтры видит сотрудник, неизвестно — уточни или найди инструментами.',
			self::TASK => 'Открыта задача (id='.$this->id.').',
			self::TASKS => 'Открыт список задач.',
			self::CALENDAR => 'Открыт календарь.',
			self::RECORD => 'Открыта карточка CRM: '.(self::NOUN[$this->entityTypeId] ?? 'запись').' (entityTypeId='.$this->entityTypeId.', id='.$this->id.'); чат открыт не из неё, поэтому её ленты ниже нет.',
			default => 'Открыта страница портала '.$this->path.'.',
		};
	}

	/**
	 * Подсказки агенту: каким инструментом взять подробности открытого.
	 * Только инструменты из $tools (имя => что угодно).
	 *
	 * @param array<string, mixed> $tools
	 * @return list<string>
	 */
	public function agentHints(array $tools, ?Record $openRecord = null): array
	{
		$hints = [];
		$add = static function(string $tool, string $text) use ($tools, &$hints): void
		{
			if(isset($tools[$tool]))
			{
				$hints[] = '- '.$tool.' '.$text;
			}
		};
		// Чат открыт без записи, а в слайдере — карточка: подсказки по ней
		$openRecord ??= $this->kind === self::RECORD ? new Record($this->entityTypeId, $this->id) : null;
		$type = $openRecord?->entityTypeId ?? $this->entityTypeId;
		$entity = json_encode(self::ENTITY[$type] ?? $type);

		if($openRecord !== null)
		{
			$add('crm_item_get', '{"entity": '.$entity.', "id": '.$openRecord->id.'} — все поля открытой записи (стадия, сумма, ответственный, свои поля);');
			$add('crm_fields', '{"entity": '.$entity.'} — названия полей, если коды непонятны;');
			$add('crm_stages', '{"entity": '.$entity.'} — названия стадий;');
			if(isset(self::ENTITY[$type]))
			{
				$add('crm_activity_list', '{"ownerType": '.$entity.', "ownerId": '.$openRecord->id.'} — дела и звонки записи, если ленты выше не хватает;');
			}
		}
		elseif($this->kind === self::CRM_LIST)
		{
			$add('crm_item_list', '{"entity": '.$entity.($this->categoryId >= 0 ? ', "filter": {"categoryId": '.$this->categoryId.'}' : '').'} — записи этого списка (фильтр — по схеме инструмента);');
			$add('crm_funnel', '{"entity": '.$entity.($this->categoryId >= 0 ? ', "categoryId": '.$this->categoryId : '').'} — воронка по стадиям;');
			$add('crm_stages', '{"entity": '.$entity.'} — стадии;');
		}
		elseif($this->kind === self::TASK)
		{
			$add('task_get', '{"id": '.$this->id.'} — открытая задача: описание, сроки, участники;');
			$add('task_comments', '{"taskId": '.$this->id.'} — комментарии задачи;');
			$add('task_checklist', '{"taskId": '.$this->id.'} — чек-лист задачи;');
		}
		elseif($this->kind === self::TASKS)
		{
			$add('task_list', '— задачи сотрудника;');
		}
		elseif($this->kind === self::CALENDAR)
		{
			$add('calendar_events', '{"from": "ГГГГ-ММ-ДД", "to": "ГГГГ-ММ-ДД"} — события календаря сотрудника;');
		}

		return $hints === [] ? [] : array_merge(['Подробности открытого — инструментами (сначала ответь из того, что уже есть выше; инструмент — когда данных не хватает):'], $hints);
	}
}
