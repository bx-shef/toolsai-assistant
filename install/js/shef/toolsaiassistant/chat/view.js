/**
 * Вид переписки (1.4.0) — как чат BitrixGPT (CoPilot) в мессенджере
 * (im/install/js/im/v2/component/message/copilot/*): приветственная карточка
 * роли, свои сообщения справа, ответы помощника слева с иконкой и подписью
 * роли, время у сообщений. Чистые функции, без DOM (tests/view_test.mjs):
 * готовят модель и HTML пузыря. ВСЁ, что пришло от сотрудника или модели,
 * экранируется: ответ — Markdown.render (экранирует целиком), вопрос и
 * подписи — escape().
 */
(function()
{
	'use strict';

	var NS = BX.namespace('BX.Shef.ToolsAiAssistant');

	function md() { return NS.Markdown; }

	function escape(text)
	{
		return md() ? md().escape(text) : String(text)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	/** Иконка ui.icon-set: только код вида o-person (из сервера, RoleIcon). */
	function iconOf(role)
	{
		var icon = role && typeof role.icon === 'string' ? role.icon : '';
		return /^[a-z][a-z0-9-]{1,60}$/.test(icon) ? icon : 'o-copilot';
	}

	/**
	 * Картинка роли (1.6.0): iconUrl из библиотеки промптов shef.toolsai —
	 * путь на портале или https-адрес; кавычки, пробелы, «//хост» и прочие
	 * схемы — пусто (тогда иконка iconOf). Как Chat\RoleIcon::url().
	 */
	function iconUrlOf(role)
	{
		var url = role && typeof role.iconUrl === 'string' ? role.iconUrl : '';
		return /^(\/(?!\/)|https:\/\/)[^\s"'<>\\()]{1,500}$/.test(url) ? url : '';
	}

	/**
	 * Первое уточнение «[ … ]» в тексте (1.6.0): {start, end} — для выделения
	 * в поле ввода, скобки входят; нет — null. Правило то же, что
	 * Prompt\Definition::placeholders() shef.toolsai: внутри без скобок и
	 * переводов строки, до 200 знаков.
	 */
	function placeholder(text, from)
	{
		var re = /\[[^\[\]\r\n]{1,200}\]/g;
		re.lastIndex = from || 0;
		var m = re.exec(String(text || ''));
		while(m && m[0].slice(1, -1).trim() === '') { m = re.exec(String(text || '')); }
		return m ? {start: m.index, end: m.index + m[0].length} : null;
	}

	function pad(n) { return (n < 10 ? '0' : '') + n; }

	var View = {
		escape: escape,
		iconOf: iconOf,
		iconUrlOf: iconUrlOf,
		placeholder: placeholder,

		/** «ЧЧ:ММ» по отметке времени (мс); нет — ''. */
		time: function(ms, offsetMinutes)
		{
			ms = Number(ms);
			if(!(ms > 0)) { return ''; }
			var d = new Date(ms - (offsetMinutes || 0) * 60000);
			return offsetMinutes === undefined
				? pad(d.getHours()) + ':' + pad(d.getMinutes())
				: pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
		},

		/**
		 * Параметры слайдера для ссылки своего портала (1.4.0): справка
		 * shef.toolsai (/shef/toolsai/kb/…) — как «?» на странице его настроек,
		 * остальное — как раньше.
		 */
		linkOptions: function(pathname)
		{
			return String(pathname || '').indexOf('/shef/toolsai/kb/') === 0
				? {width: 760, cacheable: false, allowChangeHistory: false}
				: {cacheable: false};
		},

		/** Роль по коду из списка открытых; нет — null. */
		role: function(roles, code)
		{
			for(var i = 0; i < (roles || []).length; i++)
			{
				if(roles[i].code === code) { return roles[i]; }
			}
			return null;
		},

		/**
		 * Приветственная карточка роли: «Привет! Я — <роль>», описание, готовые
		 * вопросы. Текст — для textContent (не HTML).
		 */
		welcome: function(role, options)
		{
			options = options || {};
			var title = role ? 'Привет! Я — ' + role.title : 'ИИ-помощник';
			var about = role && role.description ? role.description : 'Отвечаю на вопросы по работе в CRM.';
			var scope = options.record
				? 'Отвечаю по истории записи «' + options.record + '»: звонки, письма, чаты, дела' + (options.kb ? ' и статьи базы знаний.' : '.')
				: (options.docs ? 'Отвечаю по справке.' : 'Общий чат. Чтобы спросить по записи, откройте «✦ Спросить ИИ» в её карточке или строке списка.');
			return {title: title, text: about, scope: scope, icon: iconOf(role), iconUrl: iconUrlOf(role), examples: (options.examples || []).map(String)};
		},

		/**
		 * Модель сообщения: сторона, автор, иконка, время и безопасный HTML.
		 * @param {{role: string, text: string, roleCode?: string, time?: number}} message
		 */
		message: function(message, roles, offsetMinutes)
		{
			var mine = message.role === 'user';
			var role = mine ? null : this.role(roles, message.roleCode);
			return {
				side: mine ? 'right' : 'left',
				author: mine ? '' : (role ? role.title : 'ИИ-помощник'),
				icon: mine ? '' : iconOf(role),
				iconUrl: mine ? '' : iconUrlOf(role),
				time: this.time(message.time, offsetMinutes),
				html: mine
					? escape(message.text).replace(/\n/g, '<br>')
					: (md() ? md().render(message.text) : escape(message.text))
			};
		}
	};

	NS.ChatView = View;
})();
