<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Виджеты в ответах чата (1.7.0): какие типы есть и как о них сказать модели.
 *
 * Модель вставляет в ответ блок ```widget с JSON {"type": "…", …}; окно рисует
 * его реестром install/js/.../chat/widgets.js. Встроенные типы — BUILTIN.
 * Другой модуль добавляет свой тип в два шага (docs/guide/09-widgets.md):
 *
 *  1. JS-расширение регистрирует рисование:
 *     BX.Shef.ToolsAiAssistant.Widgets.register('мой.модуль:погода', {render: function(data, ctx) { … }});
 *  2. обработчик события shef.toolsaiassistant:OnChatWidgetsCollect сообщает
 *     тип, расширение и описание данных для модели:
 *     new EventResult(EventResult::SUCCESS, ['widgets' => [[
 *       'type' => 'погода', 'extension' => 'мой.модуль.chat-widgets',
 *       'description' => 'Погода по городу: {"city": "…", "temp": число, "days": [{"day": "…", "max": число, "min": число}]}',
 *     ]]], 'мой.модуль');
 *
 * Тип чужого модуля хранится как «<модуль>:<тип>» — не пересечётся со встроенными
 * и с другими модулями. Разбор — чистый (widgets_test.php); сбор с событием —
 * collect() (ядро).
 */
final class WidgetCatalog
{
	public const EVENT = 'OnChatWidgetsCollect';

	/** Встроенные типы: тип => описание данных для модели. */
	public const BUILTIN = [
		'kpi' => 'Показатели плитками: {"title": "…", "items": [{"label": "…", "value": "…", "hint": "…", "trend": "up|down"}]} — до 8 штук.',
		'table' => 'Таблица: {"title": "…", "columns": ["…"], "rows": [["…"]]} — до 12 столбцов и 50 строк. Ячейка-ссылка на запись CRM — {"text": "…", "entityTypeId": число, "id": число}, на страницу портала — {"text": "…", "url": "/…"}.',
		'bars' => 'Полосы (простой график): {"title": "…", "unit": "…", "items": [{"label": "…", "value": число}]}.',
		'record' => 'Карточка записи CRM со ссылкой: {"entityTypeId": число, "id": число, "title": "…", "subtitle": "…", "stage": "…", "fields": [{"label": "…", "value": "…"}]}. Только записи из данных выше.',
		'funnel' => 'Воронка с конверсией по стадиям: {"title": "…", "unit": "…", "items": [{"label": "стадия", "value": число}]} — стадии по порядку, конверсию окно считает само.',
		'score' => 'Оценка работы: {"title": "…", "value": число, "max": 100, "label": "…", "summary": "…", "criteria": [{"label": "…", "passed": true, "note": "…"}]} — до 20 критериев.',
		'missedcall' => 'Пропущенный звонок с кнопкой «Перезвонить»: {"phone": "…", "name": "…", "time": "…", "waiting": "…", "entityTypeId": число, "id": число, "note": "…"}. Только звонки из данных выше.',
		'tasks' => 'Список задач: {"title": "…", "items": [{"id": число, "title": "…", "deadline": "…", "overdue": true, "responsible": "…", "status": "…", "urgent": true, "note": "…"}]} — до 20; название ведёт в задачу.',
		'task' => 'Карточка задачи: {"id": число, "title": "…", "status": "…", "deadline": "…", "overdue": false, "responsible": "…", "creator": "…", "description": "…", "checklist": [{"label": "…", "done": true}], "entityTypeId": число, "entityId": число, "entityTitle": "…"}.',
		'today' => 'Главное на сегодня: {"title": "…", "items": [{"kind": "task|call|meeting|deal|lead|email|chat|booking", "title": "…", "time": "ЧЧ:ММ", "urgent": true, "note": "…", "taskId": число, "entityTypeId": число, "id": число}]} — до 15; urgent = горит, идёт первым.',
		'support' => 'Обращения клиентов (саппорт): {"title": "…", "items": [{"title": "…", "client": "…", "channel": "…", "waiting": "…", "status": "…", "sla": "breach|risk|ok", "entityTypeId": число, "id": число, "url": "/…"}]} — до 20.',
		'birthday' => 'День рождения сотрудника: {"userId": число, "name": "…", "position": "…", "date": "…", "message": "текст поздравления"} — сотрудник правит текст и сам отправляет его в личный чат.',
		'doc' => 'Страницы документации: {"title": "…", "items": [{"title": "…", "url": "/… или https://…", "summary": "…", "section": "…", "updated": "…", "tags": ["…"]}]} — до 8; только страницы из данных выше.',
		'schedule' => 'Расписание дня как в календаре: {"date": "…", "now": "ЧЧ:ММ", "events": [{"title": "…", "from": "ЧЧ:ММ", "to": "ЧЧ:ММ", "kind": "meeting|call|task|deal|booking", "place": "…", "allDay": false, "eventId": число, "bookingId": число, "taskId": число}]} — до 30.',
		'booking' => 'Записи на приём (онлайн-запись): {"date": "…", "waitlist": число, "items": [{"id": число, "from": "ЧЧ:ММ", "to": "ЧЧ:ММ", "resource": "специалист или кабинет", "client": "…", "phone": "…", "service": "…", "confirmed": true, "visit": "unknown|visited|notVisited", "note": "…", "entityTypeId": число, "entityId": число}]} — до 20.',
		'actions' => 'Кнопки следующего шага: {"items": [{"label": "…", "prompt": "текст запроса", "send": false}]} — до 6; send = true отправит запрос сразу.',
	];

	/** Сколько типов модулей принимаем (подсказка модели не должна разрастаться). */
	public const MAX_FOREIGN = 20;

	/**
	 * Типы модуля $owner из ответа на событие; мусор отбрасывается.
	 *
	 * @return list<array{type: string, extension: string, description: string}>
	 */
	public static function fromModule(array $raw, string $owner): array
	{
		$owner = strtolower(trim($owner));
		if(!preg_match('/^[a-z0-9_.]{1,30}$/', $owner))
		{
			return [];
		}
		$out = [];
		foreach(is_array($raw['widgets'] ?? null) ? $raw['widgets'] : [] as $item)
		{
			if(!is_array($item))
			{
				continue;
			}
			$type = strtolower(trim((string)($item['type'] ?? '')));
			$extension = trim((string)($item['extension'] ?? ''));
			$description = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($item['description'] ?? ''))) ?? '');
			if(!preg_match('/^[a-z0-9_.-]{1,30}$/', $type) || !preg_match('/^[a-z0-9_.-]{1,100}$/', $extension) || $description === '')
			{
				continue;
			}
			$out[] = ['type' => $owner.':'.$type, 'extension' => $extension, 'description' => mb_substr($description, 0, 500)];
		}

		return $out;
	}

	/**
	 * Подсказка модели: как вставить виджет и какие типы есть.
	 *
	 * @param list<array{type: string, description: string}> $foreign
	 */
	public static function instructions(array $foreign): string
	{
		$lines = [
			'Виджеты: когда цифры, сравнение, список записей или следующий шаг нагляднее показать, вставь в ответ блок',
			'```widget',
			'{"type": "kpi", "items": [{"label": "Сделок в работе", "value": 12}]}',
			'```',
			'Один блок — один виджет, внутри только JSON. Данные — только из блоков ниже, не выдумывай. Не больше трёх виджетов в ответе; короткий текст рядом всё равно пиши.',
			'Типы:',
		];
		foreach(self::BUILTIN as $type => $description)
		{
			$lines[] = '- '.$type.': '.$description;
		}
		foreach(array_slice($foreign, 0, self::MAX_FOREIGN) as $item)
		{
			$lines[] = '- '.$item['type'].': '.$item['description'];
		}

		return implode("\n", $lines);
	}

	/**
	 * Типы модулей с портала: событие OnChatWidgetsCollect. Сбой одного
	 * обработчика — без его виджетов, остальные остаются.
	 *
	 * @return list<array{type: string, extension: string, description: string}>
	 */
	public static function collect(): array
	{
		static $cache = null;
		if($cache !== null)
		{
			return $cache;
		}
		$cache = [];
		if(!class_exists(\Bitrix\Main\Event::class))
		{
			return $cache;
		}
		try
		{
			$event = new \Bitrix\Main\Event(\Shef\ToolsAiAssistant\Main\Constants::MODULE_ID, self::EVENT);
			$event->send();
			foreach($event->getResults() as $result)
			{
				if($result->getType() === \Bitrix\Main\EventResult::SUCCESS && is_array($result->getParameters()))
				{
					$cache = [...$cache, ...static::fromModule($result->getParameters(), (string)$result->getModuleId())];
				}
			}
		}
		catch(\Throwable)
		{
			// Виджеты модулей — дополнение: без них чат работает на встроенных
		}

		return $cache = array_slice($cache, 0, self::MAX_FOREIGN);
	}

	/** Для окна: тип и расширение модулей (встроенные грузить не нужно). @return list<array{type: string, extension: string}> */
	public static function forClient(array $foreign): array
	{
		return array_map(static fn(array $w): array => ['type' => $w['type'], 'extension' => $w['extension']], $foreign);
	}
}
