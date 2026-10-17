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
 * ctx: {prefill(text), send(text), el(tag, cls, text), safeUrl(url), recordUrl(entityTypeId, id)}.
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

		/** Таблица: {columns: [..], rows: [[..], ..]} — до 12 столбцов и 50 строк. */
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
					list(row, columns.length || LIMIT_ITEMS).forEach(function(cell) { tr.appendChild(ctx.el('td', '', str(cell, 200))); });
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
