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
 * ctx: {prefill(text), send(text), el(tag, cls, text), safeUrl(url), recordUrl(entityTypeId, id), call(phone, binding)}.
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

		recordUrl: recordUrl
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
