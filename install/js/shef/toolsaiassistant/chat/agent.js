/**
 * Режим «Агент» в окне чата (1.10.0): помощник действует в портале инструментами
 * MCP-сервера toolsai-mcp от имени сотрудника.
 *
 * Здесь — вид и данные, без запросов: шаги инструментов под ответом,
 * карточка «Выполнить / Отмена» для изменений, тексты подключения. Запросы и
 * состояние держит chat.js (shef:toolsaiassistant.agent.run|status|connect).
 * Всё от сервера — только textContent (как в widgets.js), без innerHTML.
 *
 * События окна для других модулей (BX.onCustomEvent(window, …)):
 *   Shef.ToolsAiAssistant.Agent:step     — [step]   шаг выполнен (инструмент, чтение, статус);
 *   Shef.ToolsAiAssistant.Agent:pending  — [pending] агент ждёт «Выполнить»;
 *   Shef.ToolsAiAssistant.Agent:decision — [pending, 'yes'|'no'] сотрудник решил.
 *
 * Тест — tests/agent_test.mjs (подставной DOM).
 */
(function(root)
{
	'use strict';

	var STATUS = {
		done: ['✓', 'выполнено'],
		error: ['!', 'ошибка'],
		denied: ['⛔', 'запрещено на портале'],
		declined: ['✕', 'отменено']
	};
	/** Окно ждёт подключения дольше — снимает подписку (чтобы не копились). */
	var MESSAGE_TYPE = 'shef-toolsaiassistant-mcp';

	function str(value, max)
	{
		var text = value === null || value === undefined || typeof value === 'object' ? '' : String(value);
		return text.length > max ? text.slice(0, max - 1) + '…' : text;
	}

	var Agent = {
		MESSAGE_TYPE: MESSAGE_TYPE,
		EVENT_STEP: 'Shef.ToolsAiAssistant.Agent:step',
		EVENT_PENDING: 'Shef.ToolsAiAssistant.Agent:pending',
		EVENT_DECISION: 'Shef.ToolsAiAssistant.Agent:decision',

		/** Подпись шага: «✓ Найти в CRM — выполнено». */
		stepLabel: function(step)
		{
			var s = STATUS[step && step.status] || ['•', ''];
			var title = str(step && (step.title || step.tool), 80);
			return s[0] + ' ' + title + (s[1] ? ' — ' + s[1] : '') + (step && step.note ? ': ' + str(step.note, 200) : '');
		},

		/**
		 * Какие сообщения переписки уходят серверу в режиме агента: без ответов,
		 * которые только объявили действие (их текст уже есть в trace).
		 */
		history: function(messages)
		{
			return (messages || []).filter(function(m) { return !m.pending && m.text !== ''; }).map(function(m)
			{
				return m.files ? {role: m.role, text: m.text, files: m.files} : {role: m.role, text: m.text};
			});
		},

		/** Ответ сервера → сообщение помощника для переписки. */
		message: function(data)
		{
			var message = {role: 'assistant', text: str(data && data.text, 20000), sources: (data && data.sources) || [], roleCode: String((data && data.role) || ''), time: Date.now(), agent: true};
			var steps = Array.isArray(data && data.steps) ? data.steps : [];
			if(steps.length) { message.steps = steps.map(function(s) { return {tool: str(s.tool, 64), title: str(s.title, 120), status: str(s.status, 20), readOnly: !!s.readOnly, note: str(s.note, 300)}; }); }
			if(data && data.pending && typeof data.pending.id === 'string')
			{
				message.pending = {id: data.pending.id, tool: str(data.pending.tool, 64), title: str(data.pending.title, 120), readOnly: !!data.pending.readOnly, preview: str(data.pending.preview, 4000)};
			}
			return message;
		},

		/** Сообщение окна подключения — от своего сайта и нашего типа. */
		isConnectMessage: function(event, origin)
		{
			return !!event && event.origin === origin && !!event.data && event.data.type === MESSAGE_TYPE;
		},

		/** Шаги под ответом. */
		renderSteps: function(steps, el)
		{
			var box = el('div', 'shef-aia-agent-steps');
			(steps || []).forEach(function(step)
			{
				var row = el('div', 'shef-aia-agent-step --' + str(step.status, 20).replace(/[^a-z]/g, ''), Agent.stepLabel(step));
				row.setAttribute('title', step.readOnly ? 'Чтение данных портала' : 'Изменение данных портала');
				box.appendChild(row);
			});
			return box;
		},

		/**
		 * Карточка «Агент хочет …»: что и с какими параметрами; кнопки — только
		 * пока сотрудник не решил ($decided — 'yes' | 'no' | '').
		 */
		renderPending: function(pending, decided, el, onDecide)
		{
			var card = el('div', 'shef-aia-agent-pending');
			card.setAttribute('role', 'group');
			card.appendChild(el('div', 'shef-aia-agent-pending-title', (pending.readOnly ? 'Агент хочет посмотреть: ' : 'Агент хочет выполнить: ') + pending.title));
			if(pending.preview && pending.preview !== '{}' && pending.preview !== '[]')
			{
				// Целиком (сервер не даёт подтвердить длиннее 4000 знаков): сотрудник видит всё, что выполнится
				card.appendChild(el('pre', 'shef-aia-agent-pending-args', pending.preview));
			}
			if(decided)
			{
				card.appendChild(el('div', 'shef-aia-agent-pending-done', decided === 'yes' ? 'Выполняю…' : 'Отменено'));
				return card;
			}
			var row = el('div', 'shef-aia-agent-pending-buttons');
			var yes = el('button', 'ui-btn ui-btn-xs ui-btn-success', 'Выполнить');
			yes.setAttribute('type', 'button');
			yes.addEventListener('click', function() { onDecide('yes'); });
			var no = el('button', 'ui-btn ui-btn-xs ui-btn-light-border', 'Отмена');
			no.setAttribute('type', 'button');
			no.addEventListener('click', function() { onDecide('no'); });
			row.appendChild(yes);
			row.appendChild(no);
			card.appendChild(row);
			return card;
		},

		/** Сообщить модулям (BX.onCustomEvent), если ядро есть. */
		emit: function(name, args)
		{
			try { if(root.BX && root.BX.onCustomEvent) { root.BX.onCustomEvent(root, name, args); } } catch(e) {}
		}
	};

	if(typeof module === 'object' && module.exports)
	{
		module.exports = Agent;
	}
	else if(root.BX && root.BX.namespace)
	{
		root.BX.namespace('BX.Shef.ToolsAiAssistant').Agent = Agent;
	}
})(typeof window !== 'undefined' ? window : this);
