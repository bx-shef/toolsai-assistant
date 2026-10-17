/**
 * Виджеты в ответах чата (1.7.0) — как карточки «погоды» в шаблоне чата Nuxt UI:
 * модель вставляет в ответ блок
 *
 *   ```widget
 *   {"type": "kpi", "items": [{"label": "Сделок", "value": 12}]}
 *   ```
 *
 * markdown.js оставляет на его месте <div data-shef-widget="N">, а этот реестр
 * рисует виджет DOM-узлами (textContent — без innerHTML: всё, что прислала
 * модель, — данные, а не разметка).
 *
 * Универсально: любой модуль добавляет свой тип —
 *   BX.Shef.ToolsAiAssistant.Widgets.register('мой.модуль:тип', {render: function(data, ctx) { return node; }});
 * в своём JS-расширении, а модели о нём сообщает событием
 * shef.toolsaiassistant:OnChatWidgetsCollect (docs/guide/09-widgets.md).
 *
 * ctx: {prefill(text), send(text), el(tag, cls, text), safeUrl(url), recordUrl(entityTypeId, id), call(phone, binding),
 *      userId (текущий сотрудник), greet(userId, text) → Promise (1.11.0)}.
 * renderer.ownTitle = true — виджет сам показывает data.title (общий заголовок не рисуется).
 * Нет такого типа или виджет упал — подпись «Виджет недоступен», ответ не ломается.
 *
 * Без зависимостей от BX внутри рисования: в тесте (tests/widgets_test.mjs) —
 * подставной document.
 */
(function(root)
{
	'use strict';

	var registry = {};
	var LIMIT_ITEMS = 12;
	var LIMIT_ROWS = 50;

	function str(value, max)
	{
		if(value === null || value === undefined) { return ''; }
		if(typeof value === 'object') { return ''; }
		var text = String(value).replace(/\s+/g, ' ').trim();
		return text.length > (max || 200) ? text.slice(0, (max || 200) - 1) + '…' : text;
	}

	function list(value, max)
	{
		return Array.isArray(value) ? value.slice(0, max || LIMIT_ITEMS) : [];
	}

	/** Путь карточки записи CRM на портале; мусор — ''. */
	function recordUrl(entityTypeId, id)
	{
		entityTypeId = Number(entityTypeId);
		id = Number(id);
		if(!(entityTypeId > 0 && entityTypeId < 1e9 && Math.floor(entityTypeId) === entityTypeId && id > 0 && id < 1e12 && Math.floor(id) === id)) { return ''; }
		var named = {1: 'lead', 2: 'deal', 3: 'contact', 4: 'company'}[entityTypeId];
		return named ? '/crm/' + named + '/details/' + id + '/' : '/crm/type/' + entityTypeId + '/details/' + id + '/';
	}

	/** Число для полосы: конечное и не меньше нуля; иначе null. */
	function amount(value)
	{
		var n = typeof value === 'number' ? value : Number(String(value).replace(/\s+/g, '').replace(',', '.'));
		return isFinite(n) && n >= 0 ? n : null;
	}

	/** Ссылка ячейки таблицы: запись CRM ({entityTypeId, id}) или путь своего портала ({url: "/…"}); иначе ''. */
	function cellUrl(cell, ctx)
	{
		var url = ctx.recordUrl(cell.entityTypeId, cell.id);
		if(!url && typeof cell.url === 'string' && /^\/(?![\/\\])/.test(cell.url.trim()))
		{
			url = (ctx.safeUrl && ctx.safeUrl(cell.url)) || '';
		}
		return url;
	}

	/** Ячейка таблицы: строка, число или {text, entityTypeId, id | url} — ссылка открывается слайдером. */
	function cellNode(cell, ctx)
	{
		var td = ctx.el('td', '');
		if(cell && typeof cell === 'object' && !Array.isArray(cell))
		{
			var text = str(cell.text, 200);
			var url = cellUrl(cell, ctx);
			if(url)
			{
				var a = ctx.el('a', 'shef-aiw-link', text || url);
				a.setAttribute('href', url);
				a.setAttribute('data-shef-link', '1');
				td.appendChild(a);
				return td;
			}
			td.textContent = text;
			return td;
		}
		td.textContent = str(cell, 200);
		return td;
	}

	/** Процент a от b целым; b не больше нуля — null. */
	function percent(a, b)
	{
		return b > 0 ? Math.round(a / b * 100) : null;
	}

	/** Номер телефона: цифры и ведущий «+», 5–20 цифр; иначе ''. */
	function phone(value)
	{
		var raw = str(value, 40);
		var digits = raw.replace(/\D+/g, '');
		if(digits.length < 5 || digits.length > 20) { return ''; }
		return (raw.charAt(0) === '+' ? '+' : '') + digits;
	}

	/** Целое ID > 0 или 0. */
	function intId(value)
	{
		var n = Number(value);
		return n > 0 && n < 1e12 && Math.floor(n) === n ? n : 0;
	}

	/** Карточка задачи (1.11.0): путь задач текущего сотрудника; мусор — ''. */
	function taskUrl(taskId, userId)
	{
		taskId = intId(taskId);
		userId = intId(userId);
		return taskId && userId ? '/company/personal/user/' + userId + '/tasks/task/view/' + taskId + '/' : '';
	}

	/** Профиль сотрудника (1.11.0); мусор — ''. */
	function userUrl(userId)
	{
		userId = intId(userId);
		return userId ? '/company/personal/user/' + userId + '/' : '';
	}

	/** Ссылка, которую открывает обработчик ссылок чата (свой портал — слайдером). */
	function link(ctx, url, text, cls)
	{
		var a = ctx.el('a', cls || 'shef-aiw-link', text);
		a.setAttribute('href', url);
		a.setAttribute('data-shef-link', '1');
		return a;
	}

	/** Срок задачи: строка как есть, просрочка — красным. */
	function deadline(ctx, item)
	{
		var text = str(item.deadline, 40);
		if(!text) { return null; }
		return ctx.el('span', 'shef-aiw-due' + (item.overdue === true ? ' --overdue' : ''), (item.overdue === true ? 'просрочена · ' : 'до ') + text);
	}

	/** Строка задачи для списков tasks и today: название-ссылка, срок, ответственный. */
	function taskRow(ctx, item, extra)
	{
		var row = ctx.el('div', 'shef-aiw-task-row' + (item.urgent === true ? ' --urgent' : ''));
		if(item.urgent === true) { row.appendChild(ctx.el('span', 'shef-aiw-fire', '🔥')); }
		var main = ctx.el('div', 'shef-aiw-task-main');
		var title = str(item.title, 160) || (intId(item.id) ? 'Задача #' + intId(item.id) : 'Задача');
		var url = extra && extra.url !== undefined ? extra.url : taskUrl(item.id, ctx.userId);
		main.appendChild(url ? link(ctx, url, title, 'shef-aiw-link shef-aiw-task-title') : ctx.el('span', 'shef-aiw-task-title', title));
		var meta = ctx.el('div', 'shef-aiw-task-meta');
		if(extra && extra.kind) { meta.appendChild(ctx.el('span', 'shef-aiw-kind', extra.kind)); }
		var due = deadline(ctx, item);
		if(due) { meta.appendChild(due); }
		var who = str(item.responsible, 80);
		if(who) { meta.appendChild(ctx.el('span', '', who)); }
		var status = str(item.status, 40);
		if(status) { meta.appendChild(ctx.el('span', 'shef-aiw-badge', status)); }
		if(meta.children.length) { main.appendChild(meta); }
		if(item.note) { main.appendChild(ctx.el('div', 'shef-aiw-task-note', str(item.note, 200))); }
		row.appendChild(main);
		return row;
	}

	/** Время «ЧЧ:ММ» → минуты от начала дня; мусор — null. */
	function minutes(value)
	{
		var m = /^(\d{1,2}):(\d{2})$/.exec(str(value, 5));
		if(!m) { return null; }
		var h = Number(m[1]), mm = Number(m[2]);
		return h <= 24 && mm < 60 && h * 60 + mm <= 1440 ? h * 60 + mm : null;
	}

	/**
	 * Раскладка дня (1.11.0, как в Google Календаре): события по времени,
	 * пересекающиеся делят ширину на колонки. Вход — [{from, to}] в минутах,
	 * выход — тот же порядок с col и cols.
	 */
	function layoutDay(events)
	{
		var order = events.map(function(e, i) { return i; }).sort(function(a, b) { return (events[a].from - events[b].from) || (events[b].to - events[a].to) || a - b; });
		var out = events.map(function() { return {col: 0, cols: 1}; });
		var group = [], ends = [], groupEnd = -1;
		function close()
		{
			group.forEach(function(i) { out[i].cols = ends.length; });
			group = []; ends = [];
		}
		order.forEach(function(i)
		{
			var e = events[i];
			if(group.length && e.from >= groupEnd) { close(); groupEnd = -1; }
			var col = 0;
			while(col < ends.length && ends[col] > e.from) { col++; }
			ends[col] = e.to;
			out[i].col = col;
			group.push(i);
			groupEnd = Math.max(groupEnd, e.to);
		});
		close();
		return out;
	}

	/** Событие календаря сотрудника (1.11.0); мусор — ''. */
	function eventUrl(eventId, userId)
	{
		eventId = intId(eventId);
		userId = intId(userId);
		return eventId && userId ? '/company/personal/user/' + userId + '/calendar/?EVENT_ID=' + eventId : '';
	}

	/** Запись на приём модуля booking (1.11.0): слайдер /booking/detail/{ID}/; мусор — ''. */
	function bookingUrl(bookingId)
	{
		bookingId = intId(bookingId);
		return bookingId ? '/booking/detail/' + bookingId + '/' : '';
	}

	var KINDS = {task: 'Задача', call: 'Звонок', meeting: 'Встреча', deal: 'Сделка', lead: 'Лид', email: 'Письмо', chat: 'Чат', booking: 'Запись'};

	var builtins = {
		/** Показатели: {items: [{label, value, hint?, trend?: "up"|"down"}]} */
		kpi: {
			render: function(data, ctx)
			{
				var box = ctx.el('div', 'shef-aiw-kpi');
				list(data.items, 8).forEach(function(item)
				{
					if(!item || typeof item !== 'object') { return; }
					var tile = ctx.el('div', 'shef-aiw-kpi-tile');
					var value = ctx.el('div', 'shef-aiw-kpi-value', str(item.value, 40));
					if(item.trend === 'up' || item.trend === 'down')
					{
						value.appendChild(ctx.el('span', 'shef-aiw-trend --' + item.trend, item.trend === 'up' ? ' ▲' : ' ▼'));
					}
					tile.appendChild(value);
					tile.appendChild(ctx.el('div', 'shef-aiw-kpi-label', str(item.label, 60)));
					if(item.hint) { tile.appendChild(ctx.el('div', 'shef-aiw-kpi-hint', str(item.hint, 80))); }
					box.appendChild(tile);
				});
				return box;
			}
		},

		/**
		 * Таблица: {columns: [..], rows: [[..], ..]} — до 12 столбцов и 50 строк.
		 * Ячейка-ссылка — {text, entityTypeId, id} (карточка CRM) или {text, url: "/…"}
		 * (страница своего портала); открывается слайдером обработчиком ссылок чата.
		 */
		table: {
			render: function(data, ctx)
			{
				var columns = list(data.columns).map(function(c) { return str(c, 60); });
				var wrap = ctx.el('div', 'shef-aiw-table-wrap');
				var table = ctx.el('table', 'shef-aiw-table');
				if(columns.length)
				{
					var head = ctx.el('tr', '');
					columns.forEach(function(c) { head.appendChild(ctx.el('th', '', c)); });
					var thead = ctx.el('thead', '');
					thead.appendChild(head);
					table.appendChild(thead);
				}
				var tbody = ctx.el('tbody', '');
				list(data.rows, LIMIT_ROWS).forEach(function(row)
				{
					var tr = ctx.el('tr', '');
					list(row, columns.length || LIMIT_ITEMS).forEach(function(cell) { tr.appendChild(cellNode(cell, ctx)); });
					tbody.appendChild(tr);
				});
				table.appendChild(tbody);
				wrap.appendChild(table);
				return wrap;
			}
		},

		/** Полосы (простой график): {items: [{label, value, note?}], unit?} */
		bars: {
			render: function(data, ctx)
			{
				var items = list(data.items).filter(function(i) { return i && typeof i === 'object' && amount(i.value) !== null; });
				var max = Math.max.apply(null, items.map(function(i) { return amount(i.value); }).concat([0]));
				var box = ctx.el('div', 'shef-aiw-bars');
				items.forEach(function(item)
				{
					var row = ctx.el('div', 'shef-aiw-bar-row');
					row.appendChild(ctx.el('span', 'shef-aiw-bar-label', str(item.label, 60)));
					var track = ctx.el('span', 'shef-aiw-bar-track');
					var fill = ctx.el('span', 'shef-aiw-bar-fill');
					fill.style.width = (max > 0 ? Math.round(amount(item.value) / max * 100) : 0) + '%';
					track.appendChild(fill);
					row.appendChild(track);
					row.appendChild(ctx.el('span', 'shef-aiw-bar-value', str(item.value, 20) + (data.unit ? ' ' + str(data.unit, 10) : '')));
					box.appendChild(row);
				});
				return box;
			}
		},

		/** Запись CRM: {entityTypeId, id, title, subtitle?, stage?, fields: [{label, value}]} — с переходом в карточку. */
		record: {
			// Название — сама ссылка карточки: общий заголовок не дублируем
			ownTitle: true,
			render: function(data, ctx)
			{
				var card = ctx.el('div', 'shef-aiw-record');
				var url = ctx.recordUrl(data.entityTypeId, data.id);
				var title = ctx.el(url ? 'a' : 'div', 'shef-aiw-record-title', str(data.title, 120) || (data.id ? '#' + str(data.id, 12) : 'Запись'));
				if(url)
				{
					title.setAttribute('href', url);
					title.setAttribute('data-shef-link', '1');
				}
				card.appendChild(title);
				if(data.subtitle) { card.appendChild(ctx.el('div', 'shef-aiw-record-sub', str(data.subtitle, 160))); }
				if(data.stage) { card.appendChild(ctx.el('span', 'shef-aiw-record-stage', str(data.stage, 60))); }
				var fields = list(data.fields);
				if(fields.length)
				{
					var dl = ctx.el('dl', 'shef-aiw-record-fields');
					fields.forEach(function(f)
					{
						if(!f || typeof f !== 'object') { return; }
						dl.appendChild(ctx.el('dt', '', str(f.label, 60)));
						dl.appendChild(ctx.el('dd', '', str(f.value, 200)));
					});
					card.appendChild(dl);
				}
				return card;
			}
		},

		/**
		 * Воронка: {items: [{label, value}], unit?} — стадии по порядку; у каждой
		 * конверсия из предыдущей и из первой. Ширина — 40–100% от наибольшей стадии:
		 * узкая полоса всё равно вмещает название и число.
		 */
		funnel: {
			render: function(data, ctx)
			{
				var items = list(data.items).filter(function(i) { return i && typeof i === 'object' && amount(i.value) !== null; });
				var max = Math.max.apply(null, items.map(function(i) { return amount(i.value); }).concat([0]));
				var first = items.length ? amount(items[0].value) : 0;
				var box = ctx.el('div', 'shef-aiw-funnel');
				items.forEach(function(item, index)
				{
					var value = amount(item.value);
					var row = ctx.el('div', 'shef-aiw-funnel-row');
					var bar = ctx.el('div', 'shef-aiw-funnel-bar');
					bar.style.width = (40 + (max > 0 ? Math.round(value / max * 60) : 0)) + '%';
					bar.appendChild(ctx.el('span', 'shef-aiw-funnel-label', str(item.label, 60)));
					bar.appendChild(ctx.el('span', 'shef-aiw-funnel-value', str(item.value, 20) + (data.unit ? ' ' + str(data.unit, 10) : '')));
					row.appendChild(bar);
					if(index > 0)
					{
						var step = percent(value, amount(items[index - 1].value));
						var total = percent(value, first);
						var conv = ctx.el('div', 'shef-aiw-funnel-conv');
						conv.appendChild(ctx.el('span', 'shef-aiw-funnel-step', step === null ? '—' : '↓ ' + step + '%'));
						if(total !== null && index > 1) { conv.appendChild(ctx.el('span', 'shef-aiw-funnel-total', ' · от начала ' + total + '%')); }
						box.appendChild(conv);
					}
					box.appendChild(row);
				});
				if(items.length > 1 && first > 0)
				{
					box.appendChild(ctx.el('div', 'shef-aiw-funnel-sum', 'Итоговая конверсия: ' + percent(amount(items[items.length - 1].value), first) + '%'));
				}
				return box;
			}
		},

		/**
		 * Оценка работы: {value, max?, label?, summary?, criteria: [{label, passed, note?}]}
		 * — балл с цветом зоны (до 50% — плохо, до 80% — средне), критерии ✓/✗.
		 */
		score: {
			render: function(data, ctx)
			{
				var box = ctx.el('div', 'shef-aiw-score');
				var value = amount(data.value);
				var max = amount(data.max) || 100;
				var head = ctx.el('div', 'shef-aiw-score-head');
				if(value !== null)
				{
					var share = Math.min(value / max, 1);
					var zone = share < 0.5 ? 'bad' : (share < 0.8 ? 'mid' : 'good');
					var badge = ctx.el('div', 'shef-aiw-score-value --' + zone, str(data.value, 10) + (max === 100 ? '%' : ' / ' + str(max, 10)));
					head.appendChild(badge);
					var meter = ctx.el('span', 'shef-aiw-score-meter');
					var fill = ctx.el('span', 'shef-aiw-score-fill --' + zone);
					fill.style.width = Math.round(share * 100) + '%';
					meter.appendChild(fill);
					var side = ctx.el('div', 'shef-aiw-score-side');
					if(data.label) { side.appendChild(ctx.el('div', 'shef-aiw-score-label', str(data.label, 80))); }
					side.appendChild(meter);
					head.appendChild(side);
				}
				box.appendChild(head);
				if(data.summary) { box.appendChild(ctx.el('div', 'shef-aiw-score-summary', str(data.summary, 400))); }
				var criteria = list(data.criteria, 20);
				if(criteria.length)
				{
					var ul = ctx.el('ul', 'shef-aiw-score-criteria');
					criteria.forEach(function(c)
					{
						if(!c || typeof c !== 'object') { return; }
						var ok = c.passed === true;
						var li = ctx.el('li', ok ? '--ok' : '--fail');
						li.appendChild(ctx.el('span', 'shef-aiw-score-mark', ok ? '✓' : '✗'));
						li.appendChild(ctx.el('span', '', str(c.label, 120)));
						if(c.note) { li.appendChild(ctx.el('div', 'shef-aiw-score-note', str(c.note, 200))); }
						ul.appendChild(li);
					});
					box.appendChild(ul);
				}
				return box;
			}
		},

		/**
		 * Пропущенный звонок: {phone, name?, time?, waiting?, entityTypeId?, id?, note?}
		 * — «Перезвонить» звонит телефонией портала (ctx.call), карточка — слайдером,
		 * «Подготовиться» вставляет запрос в поле.
		 */
		missedcall: {
			ownTitle: true,
			render: function(data, ctx)
			{
				var card = ctx.el('div', 'shef-aiw-missed');
				var number = phone(data.phone);
				var head = ctx.el('div', 'shef-aiw-missed-head');
				head.appendChild(ctx.el('span', 'shef-aiw-missed-icon', '☎'));
				var who = ctx.el('div', 'shef-aiw-missed-who');
				who.appendChild(ctx.el('div', 'shef-aiw-missed-title', str(data.title, 80) || 'Пропущенный звонок'));
				who.appendChild(ctx.el('div', 'shef-aiw-missed-name', [str(data.name, 80), number].filter(Boolean).join(' · ')));
				head.appendChild(who);
				card.appendChild(head);
				var meta = [str(data.time, 40), data.waiting ? 'ждёт ' + str(data.waiting, 40) : ''].filter(Boolean).join(' · ');
				if(meta) { card.appendChild(ctx.el('div', 'shef-aiw-missed-meta', meta)); }
				if(data.note) { card.appendChild(ctx.el('div', 'shef-aiw-missed-note', str(data.note, 300))); }
				var actions = ctx.el('div', 'shef-aiw-actions');
				if(number && ctx.call)
				{
					var call = ctx.el('button', 'shef-aiw-action --primary', 'Перезвонить');
					call.setAttribute('type', 'button');
					call.addEventListener('click', function() { ctx.call(number, {entityTypeId: Number(data.entityTypeId) || 0, id: Number(data.id) || 0}); });
					actions.appendChild(call);
				}
				var url = ctx.recordUrl(data.entityTypeId, data.id);
				if(url)
				{
					var open = ctx.el('a', 'shef-aiw-action', 'Открыть карточку');
					open.setAttribute('href', url);
					open.setAttribute('data-shef-link', '1');
					actions.appendChild(open);
				}
				var prep = ctx.el('button', 'shef-aiw-action', 'Подготовиться к звонку');
				prep.setAttribute('type', 'button');
				prep.addEventListener('click', function() { ctx.prefill('Подготовь меня к звонку: ' + ([str(data.name, 80), number].filter(Boolean).join(', ') || 'пропущенный звонок')); });
				actions.appendChild(prep);
				card.appendChild(actions);
				return card;
			}
		},

		/**
		 * Список задач: {items: [{id, title, deadline, overdue, responsible, status, urgent, note}]}
		 * — до 20; название ведёт в карточку задачи (слайдер), просроченные — красным.
		 */
		tasks: {
			render: function(data, ctx)
			{
				var box = ctx.el('div', 'shef-aiw-tasks');
				list(data.items, 20).forEach(function(item)
				{
					if(item && typeof item === 'object') { box.appendChild(taskRow(ctx, item)); }
				});
				return box;
			}
		},

		/**
		 * Карточка задачи: {id, title, status, deadline, overdue, responsible, creator,
		 * description, checklist: [{label, done}], entityTypeId?, entityId?, entityTitle?}.
		 */
		task: {
			ownTitle: true,
			render: function(data, ctx)
			{
				var card = ctx.el('div', 'shef-aiw-taskcard');
				var title = str(data.title, 160) || (intId(data.id) ? 'Задача #' + intId(data.id) : 'Задача');
				var url = taskUrl(data.id, ctx.userId);
				card.appendChild(url ? link(ctx, url, title, 'shef-aiw-record-title') : ctx.el('div', 'shef-aiw-record-title', title));
				var meta = ctx.el('div', 'shef-aiw-task-meta');
				if(data.status) { meta.appendChild(ctx.el('span', 'shef-aiw-badge', str(data.status, 40))); }
				var due = deadline(ctx, data);
				if(due) { meta.appendChild(due); }
				card.appendChild(meta);
				var fields = ctx.el('dl', 'shef-aiw-record-fields');
				[['Ответственный', data.responsible], ['Постановщик', data.creator]].forEach(function(f)
				{
					if(str(f[1], 80)) { fields.appendChild(ctx.el('dt', '', f[0])); fields.appendChild(ctx.el('dd', '', str(f[1], 80))); }
				});
				var bound = ctx.recordUrl(data.entityTypeId, data.entityId);
				if(bound)
				{
					fields.appendChild(ctx.el('dt', '', 'CRM'));
					var dd = ctx.el('dd', '');
					dd.appendChild(link(ctx, bound, str(data.entityTitle, 120) || 'Открыть запись'));
					fields.appendChild(dd);
				}
				card.appendChild(fields);
				if(data.description) { card.appendChild(ctx.el('div', 'shef-aiw-taskcard-desc', str(data.description, 600))); }
				var checklist = list(data.checklist, 20);
				if(checklist.length)
				{
					var done = checklist.filter(function(c) { return c && c.done === true; }).length;
					card.appendChild(ctx.el('div', 'shef-aiw-check-sum', 'Чек-лист: ' + done + ' из ' + checklist.length));
					var ul = ctx.el('ul', 'shef-aiw-checklist');
					checklist.forEach(function(c)
					{
						if(!c || typeof c !== 'object') { return; }
						ul.appendChild(ctx.el('li', c.done === true ? '--done' : '', (c.done === true ? '☑ ' : '☐ ') + str(c.label, 120)));
					});
					card.appendChild(ul);
				}
				return card;
			}
		},

		/**
		 * Главное на сегодня: {items: [{kind, title, time?, urgent?, note?, taskId? | entityTypeId+id? | url?}]}
		 * — до 15; «горит» (urgent) — сверху с 🔥, остальное по порядку модели.
		 */
		today: {
			render: function(data, ctx)
			{
				var items = list(data.items, 15).filter(function(i) { return i && typeof i === 'object'; });
				var hot = items.filter(function(i) { return i.urgent === true; });
				var rest = items.filter(function(i) { return i.urgent !== true; });
				var box = ctx.el('div', 'shef-aiw-today');
				if(hot.length) { box.appendChild(ctx.el('div', 'shef-aiw-today-head --hot', 'Горит: ' + hot.length)); }
				hot.concat(rest).forEach(function(item, index)
				{
					if(hot.length && index === hot.length && rest.length) { box.appendChild(ctx.el('div', 'shef-aiw-today-head', 'Остальное')); }
					var url = item.taskId ? taskUrl(item.taskId, ctx.userId) : (ctx.recordUrl(item.entityTypeId, item.id) || cellUrl({url: item.url}, ctx));
					var kind = [KINDS[item.kind] || '', str(item.time, 20)].filter(Boolean).join(' · ');
					box.appendChild(taskRow(ctx, {title: item.title, urgent: item.urgent, note: item.note, deadline: item.deadline, overdue: item.overdue}, {url: url, kind: kind}));
				});
				return box;
			}
		},

		/**
		 * Саппорт — обращения клиентов: {items: [{title, client?, channel?, waiting?, status?,
		 * sla: "breach"|"risk"|"ok", entityTypeId?, id?, url?}]} — до 20; нарушен срок — сверху.
		 */
		support: {
			render: function(data, ctx)
			{
				var order = {breach: 0, risk: 1, ok: 2};
				var rank = function(i) { return Object.prototype.hasOwnProperty.call(order, i.sla) ? order[i.sla] : 3; };
				var items = list(data.items, 20).filter(function(i) { return i && typeof i === 'object'; })
					.map(function(i, n) { return {item: i, n: n}; })
					.sort(function(a, b) { return (rank(a.item) - rank(b.item)) || a.n - b.n; });
				var labels = {breach: 'срок нарушен', risk: 'на грани', ok: 'в срок'};
				var counts = {breach: 0, risk: 0};
				var box = ctx.el('div', 'shef-aiw-support');
				var head = ctx.el('div', 'shef-aiw-support-head');
				box.appendChild(head);
				items.forEach(function(entry)
				{
					var item = entry.item;
					if(counts[item.sla] !== undefined) { counts[item.sla]++; }
					var row = ctx.el('div', 'shef-aiw-support-row');
					row.appendChild(ctx.el('span', 'shef-aiw-sla --' + (labels[item.sla] ? item.sla : 'none'), labels[item.sla] || '—'));
					var main = ctx.el('div', 'shef-aiw-task-main');
					var title = str(item.title, 160) || 'Обращение';
					var url = ctx.recordUrl(item.entityTypeId, item.id) || cellUrl({url: item.url}, ctx);
					main.appendChild(url ? link(ctx, url, title, 'shef-aiw-link shef-aiw-task-title') : ctx.el('span', 'shef-aiw-task-title', title));
					var meta = [str(item.client, 80), str(item.channel, 40), item.waiting ? 'ждёт ' + str(item.waiting, 30) : '', str(item.status, 40)].filter(Boolean).join(' · ');
					if(meta) { main.appendChild(ctx.el('div', 'shef-aiw-task-meta', meta)); }
					row.appendChild(main);
					box.appendChild(row);
				});
				head.textContent = 'Обращений: ' + items.length + (counts.breach ? ' · нарушен срок: ' + counts.breach : '') + (counts.risk ? ' · на грани: ' + counts.risk : '');
				return box;
			}
		},

		/**
		 * День рождения сотрудника: {userId, name, date?, position?, message?}. Текст
		 * поздравления правится в поле; «Сгенерировать» просит модель, «Отправить»
		 * — второе нажатие подтверждает, сообщение уходит в личку от сотрудника (ctx.greet).
		 */
		birthday: {
			ownTitle: true,
			render: function(data, ctx)
			{
				var card = ctx.el('div', 'shef-aiw-birthday');
				var name = str(data.name, 80) || 'Сотрудник';
				var userId = intId(data.userId);
				var head = ctx.el('div', 'shef-aiw-missed-head');
				head.appendChild(ctx.el('span', 'shef-aiw-bday-icon', '🎂'));
				var who = ctx.el('div', 'shef-aiw-missed-who');
				var profile = userUrl(userId);
				who.appendChild(profile ? link(ctx, profile, name, 'shef-aiw-missed-title shef-aiw-link') : ctx.el('div', 'shef-aiw-missed-title', name));
				var sub = [str(data.position, 80), data.date ? 'день рождения ' + str(data.date, 30) : ''].filter(Boolean).join(' · ');
				if(sub) { who.appendChild(ctx.el('div', 'shef-aiw-missed-name', sub)); }
				head.appendChild(who);
				card.appendChild(head);
				var area = ctx.el('textarea', 'shef-aiw-bday-text');
				area.setAttribute('rows', '4');
				area.setAttribute('maxlength', '2000');
				area.setAttribute('placeholder', 'Своё поздравление или «Сгенерировать»');
				area.value = str(data.message, 2000);
				card.appendChild(area);
				var status = ctx.el('div', 'shef-aiw-bday-status', '');
				var actions = ctx.el('div', 'shef-aiw-actions');
				var send = ctx.el('button', 'shef-aiw-action --primary', 'Отправить в чат');
				send.setAttribute('type', 'button');
				var armed = false;
				send.addEventListener('click', function()
				{
					var text = String(area.value || '').trim();
					if(!userId || !ctx.greet) { status.textContent = 'Не понятно, кого поздравить'; return; }
					if(!text) { status.textContent = 'Напишите поздравление или нажмите «Сгенерировать»'; return; }
					if(!armed)
					{
						armed = true;
						send.textContent = 'Точно отправить ' + name + '?';
						status.textContent = 'Сообщение уйдёт в личный чат от вашего имени.';
						return;
					}
					armed = false;
					send.disabled = true;
					status.textContent = 'Отправляю…';
					Promise.resolve(ctx.greet(userId, text)).then(function()
					{
						send.textContent = 'Отправлено ✓';
						status.textContent = '';
						area.disabled = true;
					}, function(message)
					{
						send.disabled = false;
						send.textContent = 'Отправить в чат';
						status.textContent = message ? String(message) : 'Не отправилось — попробуйте ещё раз';
					});
				});
				area.addEventListener('input', function() { if(armed) { armed = false; send.textContent = 'Отправить в чат'; status.textContent = ''; } });
				var generate = ctx.el('button', 'shef-aiw-action', area.value ? 'Сгенерировать заново' : 'Сгенерировать');
				generate.setAttribute('type', 'button');
				generate.addEventListener('click', function()
				{
					ctx.send('Напиши тёплое короткое поздравление с днём рождения для ' + name + (data.position ? ' (' + str(data.position, 80) + ')' : '') + ' и покажи его виджетом birthday с полем message, userId ' + userId + '.');
				});
				actions.appendChild(send);
				actions.appendChild(generate);
				card.appendChild(actions);
				card.appendChild(status);
				return card;
			}
		},

		/**
		 * Страницы документации: {items: [{title, url, summary?, section?, updated?, tags?}]} — до 8;
		 * ссылка своего портала — слайдером, внешний сайт — новой вкладкой (обработчик ссылок чата).
		 */
		doc: {
			render: function(data, ctx)
			{
				var box = ctx.el('div', 'shef-aiw-docs');
				list(data.items, 8).forEach(function(item)
				{
					if(!item || typeof item !== 'object') { return; }
					var page = ctx.el('div', 'shef-aiw-doc');
					var title = str(item.title, 120) || 'Документ';
					var url = typeof item.url === 'string' && ctx.safeUrl ? ctx.safeUrl(item.url) || '' : '';
					page.appendChild(url ? link(ctx, url, '📄 ' + title, 'shef-aiw-link shef-aiw-doc-title') : ctx.el('div', 'shef-aiw-doc-title', '📄 ' + title));
					var meta = [str(item.section, 60), item.updated ? 'обновлено ' + str(item.updated, 30) : ''].filter(Boolean).join(' · ');
					if(meta) { page.appendChild(ctx.el('div', 'shef-aiw-task-meta', meta)); }
					if(item.summary) { page.appendChild(ctx.el('div', 'shef-aiw-doc-summary', str(item.summary, 300))); }
					var tags = list(item.tags, 6).map(function(t) { return str(t, 30); }).filter(Boolean);
					if(tags.length)
					{
						var line = ctx.el('div', 'shef-aiw-doc-tags');
						tags.forEach(function(t) { line.appendChild(ctx.el('span', 'shef-aiw-badge', t)); });
						page.appendChild(line);
					}
					box.appendChild(page);
				});
				return box;
			}
		},

		/**
		 * Расписание дня (как Google Календарь): {date?, start?: "08:00", end?: "20:00", now?: "ЧЧ:ММ",
		 * events: [{title, from: "ЧЧ:ММ", to: "ЧЧ:ММ", kind?, place?, allDay?, eventId? | bookingId? | taskId? | entityTypeId+id? | url?}]}
		 * — до 30 событий; сетка по часам, пересечения — колонками, «сейчас» — красной линией.
		 */
		schedule: {
			render: function(data, ctx)
			{
				var HOUR = 44;
				var all = list(data.events, 30).filter(function(e) { return e && typeof e === 'object'; });
				var allDay = all.filter(function(e) { return e.allDay === true; });
				var timed = all.filter(function(e) { return e.allDay !== true; }).map(function(e)
				{
					var from = minutes(e.from), to = minutes(e.to);
					if(from === null) { return null; }
					if(to === null || to <= from) { to = Math.min(from + 30, 1440); }
					return {e: e, from: from, to: to};
				}).filter(Boolean);
				var start = minutes(data.start), end = minutes(data.end);
				var first = timed.length ? Math.min.apply(null, timed.map(function(t) { return t.from; })) : 540;
				var last = timed.length ? Math.max.apply(null, timed.map(function(t) { return t.to; })) : 1080;
				start = Math.floor(Math.min(start === null ? 480 : start, first) / 60) * 60;
				end = Math.ceil(Math.max(end === null ? 1080 : end, last) / 60) * 60;
				var box = ctx.el('div', 'shef-aiw-schedule');
				if(data.date) { box.appendChild(ctx.el('div', 'shef-aiw-sched-date', str(data.date, 40))); }
				var urlOf = function(e) { return eventUrl(e.eventId, ctx.userId) || bookingUrl(e.bookingId) || (e.taskId ? taskUrl(e.taskId, ctx.userId) : '') || ctx.recordUrl(e.entityTypeId, e.id) || cellUrl({url: e.url}, ctx); };
				var label = function(e, cls) { var u = urlOf(e), t = str(e.title, 100) || 'Событие'; return u ? link(ctx, u, t, cls + ' shef-aiw-sched-link') : ctx.el('span', cls, t); };
				if(allDay.length)
				{
					var top = ctx.el('div', 'shef-aiw-sched-allday');
					allDay.forEach(function(e) { top.appendChild(label(e, 'shef-aiw-sched-chip --' + (KINDS[e.kind] ? e.kind : 'other'))); });
					box.appendChild(top);
				}
				var grid = ctx.el('div', 'shef-aiw-sched-grid');
				grid.style.height = ((end - start) / 60 * HOUR) + 'px';
				for(var h = start; h <= end; h += 60)
				{
					var line = ctx.el('div', 'shef-aiw-sched-hour');
					line.style.top = ((h - start) / 60 * HOUR) + 'px';
					line.appendChild(ctx.el('span', 'shef-aiw-sched-time', (h / 60 < 10 ? '0' : '') + (h / 60) + ':00'));
					grid.appendChild(line);
				}
				var lanes = ctx.el('div', 'shef-aiw-sched-lanes');
				var places = layoutDay(timed);
				timed.forEach(function(t, i)
				{
					var e = t.e;
					var ev = ctx.el('div', 'shef-aiw-sched-event --' + (KINDS[e.kind] ? e.kind : 'other'));
					ev.style.top = ((t.from - start) / 60 * HOUR) + 'px';
					ev.style.height = Math.max((t.to - t.from) / 60 * HOUR - 2, 18) + 'px';
					ev.style.left = (places[i].col / places[i].cols * 100) + '%';
					ev.style.width = (100 / places[i].cols) + '%';
					ev.appendChild(label(e, 'shef-aiw-sched-title'));
					ev.appendChild(ctx.el('div', 'shef-aiw-sched-meta', [str(e.from, 5) + (minutes(e.to) !== null ? '–' + str(e.to, 5) : ''), str(e.place, 60)].filter(Boolean).join(' · ')));
					lanes.appendChild(ev);
				});
				grid.appendChild(lanes);
				var now = minutes(data.now);
				if(now !== null && now >= start && now <= end)
				{
					var mark = ctx.el('div', 'shef-aiw-sched-now');
					mark.style.top = ((now - start) / 60 * HOUR) + 'px';
					grid.appendChild(mark);
				}
				box.appendChild(grid);
				if(!all.length) { box.appendChild(ctx.el('div', 'shef-aiw-missing', 'Встреч нет')); }
				return box;
			}
		},

		/**
		 * Записи на приём (модуль booking): {date?, waitlist?: число, items: [{id, from, to, resource,
		 * client?, phone?, service?, confirmed?, visit?: "unknown"|"visited"|"notVisited", note?,
		 * entityTypeId?, entityId?}]} — до 20 по времени; запись открывается слайдером
		 * /booking/detail/{ID}/, клиенту можно перезвонить, внизу — «Журнал записи».
		 */
		booking: {
			render: function(data, ctx)
			{
				var visits = {visited: ['пришёл', '--ok'], notVisited: ['не пришёл', '--fail']};
				var items = list(data.items, 20).filter(function(i) { return i && typeof i === 'object'; })
					.map(function(i, n) { return {i: i, n: n, t: minutes(i.from)}; })
					.sort(function(a, b) { return ((a.t === null ? 1e4 : a.t) - (b.t === null ? 1e4 : b.t)) || a.n - b.n; });
				var box = ctx.el('div', 'shef-aiw-booking');
				var unconfirmed = items.filter(function(x) { return x.i.confirmed === false; }).length;
				var head = [str(data.date, 40), 'записей: ' + items.length, unconfirmed ? 'не подтверждено: ' + unconfirmed : ''].filter(Boolean).join(' · ');
				box.appendChild(ctx.el('div', 'shef-aiw-support-head', head));
				items.forEach(function(x)
				{
					var item = x.i;
					var row = ctx.el('div', 'shef-aiw-booking-row');
					row.appendChild(ctx.el('div', 'shef-aiw-booking-time', [str(item.from, 5), str(item.to, 5)].filter(Boolean).join('–') || '—'));
					var main = ctx.el('div', 'shef-aiw-task-main');
					var title = str(item.client, 80) || str(item.service, 80) || 'Запись';
					var url = bookingUrl(item.id);
					main.appendChild(url ? link(ctx, url, title, 'shef-aiw-link shef-aiw-task-title') : ctx.el('span', 'shef-aiw-task-title', title));
					var meta = ctx.el('div', 'shef-aiw-task-meta');
					[str(item.resource, 60), item.client ? str(item.service, 80) : ''].filter(Boolean).forEach(function(t) { meta.appendChild(ctx.el('span', '', t)); });
					if(item.confirmed === true) { meta.appendChild(ctx.el('span', 'shef-aiw-badge --ok', 'подтверждена')); }
					if(item.confirmed === false) { meta.appendChild(ctx.el('span', 'shef-aiw-badge --warn', 'не подтверждена')); }
					if(visits[item.visit]) { meta.appendChild(ctx.el('span', 'shef-aiw-badge ' + visits[item.visit][1], visits[item.visit][0])); }
					var crm = ctx.recordUrl(item.entityTypeId, item.entityId);
					if(crm) { meta.appendChild(link(ctx, crm, 'карточка CRM')); }
					main.appendChild(meta);
					if(item.note) { main.appendChild(ctx.el('div', 'shef-aiw-task-note', str(item.note, 200))); }
					row.appendChild(main);
					var number = phone(item.phone);
					if(number && ctx.call)
					{
						var call = ctx.el('button', 'shef-aiw-icon-btn', '☎');
						call.setAttribute('type', 'button');
						call.setAttribute('title', 'Позвонить ' + number);
						call.addEventListener('click', function() { ctx.call(number, {entityTypeId: Number(item.entityTypeId) || 0, id: Number(item.entityId) || 0}); });
						row.appendChild(call);
					}
					box.appendChild(row);
				});
				var foot = ctx.el('div', 'shef-aiw-booking-foot');
				foot.appendChild(link(ctx, '/booking/', 'Журнал записи'));
				var waiting = intId(data.waitlist);
				if(waiting) { foot.appendChild(ctx.el('span', '', ' · в листе ожидания: ' + waiting)); }
				box.appendChild(foot);
				return box;
			}
		},

		/** Кнопки следующего шага: {items: [{label, prompt, send?}]} — вставить запрос в поле или сразу отправить. */
		actions: {
			render: function(data, ctx)
			{
				var box = ctx.el('div', 'shef-aiw-actions');
				list(data.items, 6).forEach(function(item)
				{
					if(!item || typeof item !== 'object') { return; }
					var prompt = str(item.prompt, 1000);
					if(!prompt) { return; }
					var button = ctx.el('button', 'shef-aiw-action', str(item.label, 60) || prompt.slice(0, 60));
					button.setAttribute('type', 'button');
					button.addEventListener('click', function() { item.send === true ? ctx.send(prompt) : ctx.prefill(prompt); });
					box.appendChild(button);
				});
				return box;
			}
		}
	};

	var Widgets = {
		/** Свой тип виджета: имя «модуль:тип» (латиница, цифры, «.», «-», «_»), renderer.render(data, ctx) → узел. */
		register: function(type, renderer)
		{
			type = String(type || '').trim().toLowerCase();
			if(!/^[a-z0-9][a-z0-9_.:-]{0,60}$/.test(type) || !renderer || typeof renderer.render !== 'function') { return false; }
			registry[type] = renderer;
			return true;
		},

		has: function(type)
		{
			return Object.prototype.hasOwnProperty.call(registry, type) || Object.prototype.hasOwnProperty.call(builtins, type);
		},

		/** Встроенные типы (для подсказки модели и теста). */
		builtins: function() { return Object.keys(builtins); },

		/** Нарисовать виджет в host; заголовок data.title — общий для всех типов. */
		mount: function(host, spec, ctx)
		{
			var renderer = spec && (registry[spec.type] || builtins[spec.type]);
			var data = spec && spec.data ? spec.data : {};
			host.classList.add('--' + String(spec && spec.type || 'unknown').replace(/[^a-z0-9-]/g, '-'));
			if(data.title && !(renderer && renderer.ownTitle)) { host.appendChild(ctx.el('div', 'shef-aiw-title', str(data.title, 120))); }
			var node = null;
			if(renderer)
			{
				try { node = renderer.render(data, ctx); } catch(e) { node = null; }
			}
			host.appendChild(node && typeof node === 'object' && node.nodeType ? node : ctx.el('div', 'shef-aiw-missing', 'Виджет недоступен: ' + str(spec && spec.type, 60)));
			return host;
		},

		recordUrl: recordUrl,
		taskUrl: taskUrl,
		layoutDay: layoutDay,
		bookingUrl: bookingUrl,
		userUrl: userUrl
	};

	if(typeof module === 'object' && module.exports)
	{
		module.exports = Widgets;
	}
	else if(root.BX && root.BX.namespace)
	{
		var ns = root.BX.namespace('BX.Shef.ToolsAiAssistant');
		// Расширение модуля могло зарегистрироваться раньше (очередь до загрузки чата)
		var queued = ns.Widgets && ns.Widgets.queue ? ns.Widgets.queue : [];
		ns.Widgets = Widgets;
		queued.forEach(function(args) { Widgets.register(args[0], args[1]); });
	}
})(typeof window !== 'undefined' ? window : this);
