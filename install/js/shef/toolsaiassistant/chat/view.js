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

	function pad(n) { return (n < 10 ? '0' : '') + n; }

	var View = {
		escape: escape,
		iconOf: iconOf,

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
			return {title: title, text: about, scope: scope, icon: iconOf(role), examples: (options.examples || []).map(String)};
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
				time: this.time(message.time, offsetMinutes),
				html: mine
					? escape(message.text).replace(/\n/g, '<br>')
					: (md() ? md().render(message.text) : escape(message.text))
			};
		}
	};

	NS.ChatView = View;
})();
