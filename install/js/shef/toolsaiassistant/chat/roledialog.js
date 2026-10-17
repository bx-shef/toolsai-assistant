/**
 * Модель диалога «Выбрать роль» (1.4.0) — как штатный диалог ролей ядра
 * (ai/install/js/ai/roles-dialog, на ui.entity-catalog): слева категории
 * (Недавние, Избранное, Все роли, группы реестра), справа роли с иконкой,
 * названием и описанием, поиск сверху. Чистые функции (tests/roledialog_test.mjs);
 * вид — в chat.js. Роли — только открытые сотруднику (их прислал сервер).
 */
(function()
{
	'use strict';

	var NS = BX.namespace('BX.Shef.ToolsAiAssistant');

	function norm(text)
	{
		return String(text || '').toLowerCase().replace(/ё/g, 'е').replace(/\s+/g, ' ').trim();
	}

	var Model = {
		RECENT: 'recent',
		FAVORITE: 'favorite',
		ALL: 'all',

		/** Категории слева: недавние и избранное — если не пусты, все роли, группы реестра. */
		categories: function(roles, prefs)
		{
			var codes = roles.map(function(r) { return r.code; });
			var has = function(list) { return (list || []).filter(function(c) { return codes.indexOf(c) !== -1; }).length; };
			var list = [];
			var recent = has(prefs && prefs.recent);
			var favorite = has(prefs && prefs.favorite);
			if(recent) { list.push({id: this.RECENT, title: 'Недавние', icon: 'o-clock', count: recent}); }
			list.push({id: this.FAVORITE, title: 'Избранное', icon: 'o-favorite', count: favorite});
			list.push({id: this.ALL, title: 'Все роли', icon: 'o-copilot', count: roles.length});
			var groups = [];
			roles.forEach(function(r)
			{
				var g = String(r.group || '').trim();
				if(g && groups.indexOf(g) === -1) { groups.push(g); }
			});
			if(groups.length > 1)
			{
				groups.forEach(function(g)
				{
					list.push({id: 'group:' + g, title: g, icon: 'o-folder', count: roles.filter(function(r) { return String(r.group || '').trim() === g; }).length});
				});
			}
			return list;
		},

		/** Категория по умолчанию: недавние, если есть, иначе все роли. */
		defaultCategory: function(roles, prefs)
		{
			var cats = this.categories(roles, prefs);
			return cats[0].id === this.RECENT ? this.RECENT : this.ALL;
		},

		/**
		 * Роли справа. Поиск — по названию и описанию во всех ролях (категория
		 * не важна), без учёта регистра и «ё».
		 * @return {Array<{code, title, description, icon, iconUrl, favorite, selected}>}
		 */
		items: function(roles, prefs, category, query, selected)
		{
			var favorite = (prefs && prefs.favorite) || [];
			var recent = (prefs && prefs.recent) || [];
			var q = norm(query);
			var list;
			if(q)
			{
				list = roles.filter(function(r) { return norm(r.title + ' ' + (r.description || '')).indexOf(q) !== -1; });
			}
			else if(category === this.RECENT || category === this.FAVORITE)
			{
				var order = category === this.RECENT ? recent : favorite;
				list = order.map(function(code) { return roles.filter(function(r) { return r.code === code; })[0]; }).filter(Boolean);
			}
			else if(String(category).indexOf('group:') === 0)
			{
				var g = String(category).slice(6);
				list = roles.filter(function(r) { return String(r.group || '').trim() === g; });
			}
			else
			{
				list = roles.slice();
			}
			return list.map(function(r)
			{
				return {
					code: r.code,
					title: String(r.title || r.code),
					description: String(r.description || ''),
					icon: NS.ChatView ? NS.ChatView.iconOf(r) : 'o-copilot',
					iconUrl: NS.ChatView ? NS.ChatView.iconUrlOf(r) : '',
					favorite: favorite.indexOf(r.code) !== -1,
					selected: r.code === selected
				};
			});
		},

		/** Текст пустой категории. */
		empty: function(category, query)
		{
			if(norm(query)) { return 'Ничего не нашлось — попробуйте другое слово.'; }
			if(category === this.FAVORITE) { return 'Отметьте роли звёздочкой — они появятся здесь.'; }
			return 'Ролей нет.';
		}
	};

	NS.RoleDialogModel = Model;
})();
