/**
 * Меню промптов в поле ввода (1.7.0) — как у задач Битрикс24 («BitrixGPT ▸
 * Изменить роль», «Сохранённые», «Библиотека промптов», «Улучши описание…»):
 * избранные сотрудником, запросы для места окна (карточка записи или список
 * CRM) и общие. Источник — библиотека промптов shef.toolsai (свои и типовые
 * модуля, TypicalPrompts), сервер уже отфильтровал «Кому доступна».
 *
 * Чистые функции без DOM (tests/promptmenu_test.mjs): группы, поиск,
 * действие по выбору.
 */
(function(root)
{
	'use strict';

	var TITLES = {favorite: 'Избранные', card: 'Для этой записи', list: 'Для списка', chat: 'Общие'};
	var ORDER = ['favorite', 'card', 'list', 'chat'];

	function norm(text)
	{
		return String(text || '').toLowerCase().replace(/ё/g, 'е').trim();
	}

	var PromptMenu = {
		titles: TITLES,

		/**
		 * Группы меню: [{id, title, items}] в порядке «избранные → место → общие»;
		 * избранный промпт — только в «Избранных»; пустые группы — прочь.
		 * query — поиск по названию и тексту (без учёта регистра и «ё»).
		 */
		groups: function(prompts, query)
		{
			var q = norm(query);
			var buckets = {favorite: [], card: [], list: [], chat: []};
			(Array.isArray(prompts) ? prompts : []).forEach(function(p)
			{
				if(!p || typeof p.title !== 'string' || typeof p.text !== 'string') { return; }
				if(q && norm(p.title).indexOf(q) < 0 && norm(p.text).indexOf(q) < 0) { return; }
				var group = p.favorite ? 'favorite' : (buckets[p.group] ? p.group : 'chat');
				buckets[group].push(p);
			});
			return ORDER.filter(function(id) { return buckets[id].length; }).map(function(id)
			{
				return {id: id, title: TITLES[id], items: buckets[id]};
			});
		},

		/** Что сделать по выбору: template — в поле ввода, иначе отправить. */
		action: function(prompt)
		{
			return prompt && prompt.type === 'template' ? 'prefill' : 'send';
		},

		/** «/» в пустом поле открывает меню (как «Нажмите / …» у задач). */
		opensOn: function(value, key)
		{
			return key === '/' && String(value || '').trim() === '';
		},

		/**
		 * Вид окна для сервера: карточка определяется записью, список — по
		 * адресу страницы CRM без записи; иначе пусто.
		 */
		view: function(hasRecord, pathname)
		{
			return !hasRecord && /^\/crm\/(?!configs\/)/.test(String(pathname || '')) ? 'list' : '';
		}
	};

	if(typeof module === 'object' && module.exports)
	{
		module.exports = PromptMenu;
	}
	else if(root.BX && root.BX.namespace)
	{
		root.BX.namespace('BX.Shef.ToolsAiAssistant').PromptMenu = PromptMenu;
	}
})(typeof window !== 'undefined' ? window : this);
