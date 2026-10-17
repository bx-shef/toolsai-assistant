/**
 * Панель справа и окно чата ИИ-помощника (shef.toolsaiassistant).
 *
 * Панель — перенос Assistant\SidePanel::script() shef.toolsai без смены смысла:
 * фиксированный блок у правого края поверх страницы, ширина тянется мышью и
 * запоминается в браузере (localStorage, удобство — не данные), «открыт» —
 * тоже; кнопка — элемент с id из настроек (кнопка «ИИ: чат» верхней панели),
 * клик открывает и закрывает; window.ShefAiChat.open({entityTypeId, id, title})
 * — чат по записи CRM, без записи — общий; только в верхнем окне.
 *
 * Окно — вместо фрейма сайта документации (Nuxt, HdChatOverlay.vue): роли
 * (переключатель — только открытые сотруднику), готовые вопросы, ответы
 * markdown, статьи базы знаний, «Копировать», «Повторить», оценка «Полезно /
 * Не помогло» (1.1.0). Сервер — BX.ajax.runAction('shef:toolsaiassistant.chat.
 * info|role|rate'); ответ печатается по ходу — поток действия stream
 * (stream.js, 1.1.0). Переписка каждой записи — своя, в localStorage браузера.
 */
(function()
{
	'use strict';

	var NS = BX.namespace('BX.Shef.ToolsAiAssistant');
	var ACTION = 'shef:toolsaiassistant.chat.';
	var HISTORY_KEEP = 40;
	/** id кнопки вида «ИИ» (Panel\SidePanel::VIEW_BUTTON_ID): её клик — в onclick, здесь только метка «открыт». */
	var VIEW_BUTTON_ID = 'shef-ai-chat-view-btn';
	/** Карточки CRM: адрес /crm/<код>/details/<id>/ → entityTypeId (\CCrmOwnerType). */
	var DETAIL_TYPES = {lead: 1, deal: 2, contact: 3, company: 4, quote: 7};

	function get(key) { try { return window.localStorage.getItem(key); } catch(e) { return null; } }
	function put(key, value) { try { window.localStorage.setItem(key, value); } catch(e) {} }
	function drop(key) { try { window.localStorage.removeItem(key); } catch(e) {} }

	function el(tag, className, text)
	{
		var node = document.createElement(tag);
		if(className) { node.className = className; }
		if(text !== undefined) { node.textContent = text; }
		return node;
	}

	/**
	 * Ключ чата — как Chat\ChatKey на сервере. Чат справки (1.3.0) — свой
	 * ключ «docs» и в браузере, и на сервере (запомненная роль — своя).
	 */
	function chatKey(record)
	{
		if(record && record.docs) { return 'docs'; }
		return record ? 'record:' + record.entityTypeId + ':' + record.id : 'general';
	}

	/** Запись для сервера: у общего чата и чата справки — пусто. */
	function recordData(record)
	{
		return record && !record.docs ? {entityTypeId: record.entityTypeId, id: record.id} : {};
	}

	/** Контекст окна для сервера: «docs» — свой ключ чата справки (Chat\ChatKey::DOCS). */
	function contextOf(record)
	{
		return record && record.docs ? 'docs' : '';
	}

	/**
	 * Что открыть из window.ShefAiChat.open(): запись CRM (только
	 * положительные целые) или чат справки {context: 'docs', role, title,
	 * question} (1.3.0, docs/guide/06-api.md).
	 */
	function normalizeRecord(rec)
	{
		if(!rec) { return null; }
		if(rec.context === 'docs')
		{
			return {
				docs: true,
				title: String(rec.title || 'Справка').slice(0, 120),
				role: /^[\w.:-]{1,100}$/.test(String(rec.role || '')) ? String(rec.role) : '',
				question: String(rec.question || '').slice(0, 2000)
			};
		}
		var type = parseInt(rec.entityTypeId, 10);
		var id = parseInt(rec.id, 10);
		if(!(type > 0) || !(id > 0)) { return null; }
		return {entityTypeId: type, id: id, title: String(rec.title || ('Запись #' + id)).slice(0, 120)};
	}

	/**
	 * Запись CRM по адресу карточки (1.3.2, пункт «✦ Спросить ИИ» меню карточки):
	 * /crm/deal/details/5/, /crm/lead|contact|company|quote/details/ID/,
	 * /crm/type/<entityTypeId>/details/ID/ (смарт-процессы, счета). Новая
	 * запись (ID 0) и чужие адреса — null.
	 */
	function recordFromPath(path)
	{
		var m = String(path || '').match(/^\/crm\/(?:type\/(\d+)|([a-z]+))\/details\/(\d+)\//);
		if(!m) { return null; }
		var type = m[1] ? parseInt(m[1], 10) : (DETAIL_TYPES[m[2]] || 0);
		var id = parseInt(m[3], 10);
		return type > 0 && id > 0 ? {entityTypeId: type, id: id} : null;
	}

	/** Запись карточки, открытой в этом окне (во фрейме слайдера — его адрес), с заголовком страницы. */
	function currentRecord()
	{
		var record = recordFromPath(window.location && window.location.pathname);
		if(record)
		{
			record.title = String(document.title || '').trim();
		}
		return record;
	}

	/**
	 * Кнопка-иконка (1.3.2): штатная иконка ui.icon-set (набор outline) и
	 * подсказка title / aria-label.
	 */
	function iconButton(className, icon, title)
	{
		var button = el('button', className);
		button.type = 'button';
		setIcon(button, icon, title);
		return button;
	}

	function setIcon(button, icon, title)
	{
		BX.cleanNode(button);
		button.appendChild(el('span', 'ui-icon-set --' + icon));
		button.title = title;
		button.setAttribute('aria-label', title);
	}

	function errorText(response, fallback)
	{
		var errors = response && response.errors;
		return errors && errors.length && errors[0].message ? String(errors[0].message) : fallback;
	}

	var Chat = {
		settings: null,
		panel: null,
		record: null,
		loaded: false,
		info: null,
		messages: [],
		busy: false,
		lastError: '',
		requestId: 0,
		/** Идущий поток ответа ({abort}) — обрываем при смене чата и очистке. */
		pending: null,
		/** Перерисовка по кускам потока — не чаще кадра. */
		frame: 0,

		/** Запуск на странице: один раз и только в верхнем окне. */
		init: function(settings)
		{
			if(window.top !== window)
			{
				Frame.init(settings);
				return;
			}
			if(document.getElementById('shef-aia-chat'))
			{
				return;
			}
			this.settings = settings;
			// Подгружены из фрейма (top.BX.load, только свои файлы) — иконки ui.icon-set доберём сами (1.3.2)
			if(settings.fromFrame && BX.Runtime && BX.Runtime.loadExtension)
			{
				try { BX.Runtime.loadExtension('ui.icon-set.outline'); } catch(e) {}
			}
			this.buildPanel();
			this.bindButton();
			this.watchSliders();
			var self = this;
			BX.ready(function() { self.bindButton(); });
			window.ShefAiChat = {
				open: function(rec) { self.open(rec); },
				close: function() { self.close(); },
				toggle: function(rec) { self.isOpen() ? self.close() : self.open(rec); },
				isOpen: function() { return self.isOpen(); },
				/** Чат по карточке CRM, открытой в этом окне (1.3.2): меню карточки. */
				openCurrent: function() { var rec = currentRecord(); if(rec) { self.open(rec); } }
			};
			// Мягкий вызов событием: BX.onCustomEvent('ShefAiChat:open', [{context: 'docs', ...}])
			BX.addCustomEvent('ShefAiChat:open', function(rec) { self.open(rec); });
			// Запущен из фрейма (Frame.top) — откроет тот, кто позвал; сам не открываемся
			if(!settings.fromFrame && get('shef-ai-chat-open') === '1' && window.innerWidth > 760)
			{
				this.open(null);
			}
		},

		/** Отступ справа под полосу мессенджера портала (#right-bar шаблона), см. layout.js. */
		inset: function()
		{
			var bar = document.getElementById('right-bar');
			var rect = bar && bar.offsetWidth > 0 ? bar.getBoundingClientRect() : null;
			return NS.ChatLayout ? NS.ChatLayout.inset(window.innerWidth, rect) : 0;
		},

		/**
		 * Ширина и место панели (1.3.2): поверх страницы, страницу не сдвигаем —
		 * иначе сдвигается и полоса мессенджера, а по ней слайдер считает свою
		 * ширину (intranet/sidepanel/air, getRightBoundary()) и сжимался.
		 */
		applyLayout: function(width)
		{
			if(!this.panel)
			{
				return;
			}
			var s = this.settings;
			var inset = this.inset();
			var saved = width === undefined ? get('shef-ai-chat-w') : width;
			var layout = NS.ChatLayout
				? NS.ChatLayout.compute(window.innerWidth, inset, saved, {min: s.min, max: s.max})
				: {width: s.def, right: 0, narrow: false};
			this.panel.style.setProperty('--shef-aia-chat-w', layout.width + 'px');
			this.panel.style.setProperty('--shef-aia-chat-r', layout.right + 'px');
			this.panel.classList.toggle('--narrow', layout.narrow);
			this.width = layout.width;
		},

		buildPanel: function()
		{
			var self = this;

			var panel = el('div');
			panel.id = 'shef-aia-chat';
			// Запасной слой: без ZIndexManager ядра (ниже — регистрация в его стопке)
			panel.style.zIndex = String(this.settings.z);
			panel.setAttribute('role', 'complementary');
			panel.setAttribute('aria-label', 'ИИ: чат');

			var drag = el('div', 'shef-aia-drag');
			drag.title = 'Потяните, чтобы изменить ширину';
			panel.appendChild(drag);

			var bar = el('div', 'shef-aia-bar');
			bar.appendChild(el('span', 'shef-aia-bar-title', 'ИИ-помощник'));
			this.clearButton = iconButton('shef-aia-icon', 'o-trashcan', 'Очистить диалог');
			this.clearButton.addEventListener('click', function() { self.askClear(true); });
			bar.appendChild(this.clearButton);
			var closeButton = iconButton('shef-aia-icon', 'cross-m', 'Закрыть чат');
			closeButton.addEventListener('click', function() { self.close(); });
			bar.appendChild(closeButton);
			panel.appendChild(bar);

			// Подтверждение очистки — в самой панели: всплывающее окно ядра легло бы под панель (она поверх всего)
			var confirmRow = el('div', 'shef-aia-confirm');
			confirmRow.setAttribute('role', 'alert');
			confirmRow.appendChild(el('span', 'shef-aia-confirm-text', 'Очистить диалог? Переписка удалится из этого браузера.'));
			var yes = el('button', 'ui-btn ui-btn-xs ui-btn-danger', 'Очистить');
			yes.type = 'button';
			yes.addEventListener('click', function() { self.askClear(false); self.clear(); });
			var no = el('button', 'ui-btn ui-btn-xs ui-btn-light-border', 'Отмена');
			no.type = 'button';
			no.addEventListener('click', function() { self.askClear(false); });
			confirmRow.appendChild(yes);
			confirmRow.appendChild(no);
			confirmRow.hidden = true;
			this.confirmRow = confirmRow;
			panel.appendChild(confirmRow);

			var roleRow = el('label', 'shef-aia-role');
			roleRow.appendChild(el('span', 'shef-aia-role-label', 'Роль:'));
			this.roleSelect = el('select', 'shef-aia-role-select');
			this.roleSelect.addEventListener('change', function() { self.chooseRole(self.roleSelect.value); });
			roleRow.appendChild(this.roleSelect);
			this.roleRow = roleRow;
			panel.appendChild(roleRow);

			this.scroller = el('div', 'shef-aia-messages');
			this.scroller.addEventListener('click', function(e) { self.onLinkClick(e); });
			panel.appendChild(this.scroller);

			var form = el('form', 'shef-aia-form');
			this.input = el('textarea', 'shef-aia-input');
			this.input.rows = 2;
			this.input.setAttribute('aria-label', 'Текст вопроса');
			this.input.placeholder = 'Спросите помощника…';
			this.input.addEventListener('keydown', function(e)
			{
				if(e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey)
				{
					e.preventDefault();
					self.send(self.input.value);
				}
			});
			this.sendButton = el('button', 'ui-btn ui-btn-primary ui-btn-round shef-aia-send', 'Спросить');
			this.sendButton.type = 'submit';
			form.addEventListener('submit', function(e) { e.preventDefault(); self.send(self.input.value); });
			var row = el('div', 'shef-aia-row');
			row.appendChild(this.input);
			row.appendChild(this.sendButton);
			form.appendChild(row);
			this.disclaimer = el('p', 'shef-aia-disclaimer', 'Ответы ИИ могут быть неточны, проверяйте важную информацию.');
			form.appendChild(this.disclaimer);
			panel.appendChild(form);

			document.body.appendChild(panel);
			this.panel = panel;
			this.applyLayout();
			// В общую стопку z-index ядра (main.core ZIndexManager), где живут слои
			// слайдеров. alwaysOnTop (1.3.2): стопка сортирует такие слои последними
			// (z-index-stack.js, sort) — панель над любым слайдером и его
			// затемнением без гонки с bringToFront() слайдера.
			if(BX.ZIndexManager && BX.ZIndexManager.getOrAddStack)
			{
				try { BX.ZIndexManager.getOrAddStack(document.body).register(panel, {alwaysOnTop: true}); } catch(e) {}
			}
			var relayout = function() { self.applyLayout(); };
			window.addEventListener('resize', relayout);

			drag.addEventListener('mousedown', function(e)
			{
				e.preventDefault();
				panel.classList.add('--drag');
				var inset = self.inset();
				function move(ev)
				{
					self.applyLayout(NS.ChatLayout ? NS.ChatLayout.dragWidth(window.innerWidth, inset, ev.clientX) : self.width);
				}
				function up()
				{
					panel.classList.remove('--drag');
					document.removeEventListener('mousemove', move);
					document.removeEventListener('mouseup', up);
					put('shef-ai-chat-w', String(self.width));
				}
				document.addEventListener('mousemove', move);
				document.addEventListener('mouseup', up);
			});
		},

		/**
		 * Поверх слайдеров (1.3.0): BX.SidePanel кладёт слой слайдера в стопку
		 * ZIndexManager и поднимает его наверх при открытии
		 * (main/install/js/main/sidepanel/src/slider.js). Открытый чат
		 * поднимаем следом — он остаётся над верхним слайдером.
		 */
		watchSliders: function()
		{
			var self = this;
			var lift = function() { self.applyLayout(); if(self.isOpen()) { self.raise(); } };
			if(BX.Event && BX.Event.EventEmitter)
			{
				BX.Event.EventEmitter.subscribe('SidePanel.Slider:onOpen', function() { setTimeout(lift, 0); });
				BX.Event.EventEmitter.subscribe('SidePanel.Slider:onOpenComplete', lift);
				BX.Event.EventEmitter.subscribe('SidePanel.Slider:onCloseComplete', lift);
			}
		},

		/** Поднять панель над всем в стопке ядра; без ZIndexManager — свой z-index. */
		raise: function()
		{
			if(BX.ZIndexManager && BX.ZIndexManager.getComponent && BX.ZIndexManager.getComponent(this.panel))
			{
				BX.ZIndexManager.bringToFront(this.panel);
			}
		},

		/** Кнопка «✦ ИИ: чат» верхней панели: открыть/закрыть. Кнопку не правим — её перестраивает BX.UI.Toolbar (1.3.1). */
		bindButton: function()
		{
			var self = this;
			var button = document.getElementById(this.settings.button);
			if(!button || button.getAttribute('data-shef-bound'))
			{
				return;
			}
			button.setAttribute('data-shef-bound', '1');
			button.addEventListener('click', function(e)
			{
				e.preventDefault();
				self.isOpen() ? self.close() : self.open(null);
			});
		},

		isOpen: function()
		{
			return !!this.panel && this.panel.classList.contains('--open');
		},

		/** Открыть общий чат (rec пусто) или чат по записи CRM. */
		open: function(rec)
		{
			var record = normalizeRecord(rec);
			// Кнопка без записи — открыть то, что было открыто; запись — её чат, если он ещё не открыт
			if(!this.loaded || (record && chatKey(record) !== chatKey(this.record)))
			{
				this.switchTo(record);
			}
			if(record && record.docs && record.question && !this.busy)
			{
				this.input.value = record.question;
			}
			this.applyLayout();
			this.panel.classList.add('--open');
			this.raise();
			// Только метка «открыт»: страницу не сдвигаем (1.3.2)
			document.body.classList.add('shef-ai-chat-open');
			this.markButtons(true);
			put('shef-ai-chat-open', '1');
			var input = this.input;
			setTimeout(function() { input.focus({preventScroll: true}); }, 50);
		},

		close: function()
		{
			this.panel.classList.remove('--open');
			document.body.classList.remove('shef-ai-chat-open');
			this.markButtons(false);
			put('shef-ai-chat-open', '0');
		},

		/**
		 * Состояние «открыт» на кнопках «✦ ИИ: чат» (1.3.2): штатный класс
		 * ui-btn-active и aria-pressed. Кнопку не перестраиваем — только метка.
		 */
		markButtons: function(open)
		{
			[this.settings.button, VIEW_BUTTON_ID].forEach(function(id)
			{
				var button = id && document.getElementById(id);
				if(button)
				{
					button.classList.toggle('ui-btn-active', open);
					button.setAttribute('aria-pressed', open ? 'true' : 'false');
				}
			});
		},

		/** Другой чат: своя переписка, свои роли и вопросы с сервера. */
		switchTo: function(record)
		{
			var self = this;
			this.record = record;
			this.loaded = true;
			this.info = null;
			this.lastError = '';
			this.requestId++;
			this.stopStream();
			this.busy = false;
			this.messages = this.loadMessages();
			this.render();
			var requestId = this.requestId;
			BX.ajax.runAction(ACTION + 'info', {data: {record: recordData(record), context: contextOf(record)}})
				.then(function(response)
				{
					if(requestId !== self.requestId) { return; }
					self.info = response.data;
					self.render();
					self.applyDocsRole();
				}, function(response)
				{
					if(requestId !== self.requestId) { return; }
					self.info = {roles: [], role: null, examples: [], model: '', kb: false, error: errorText(response, 'Чат недоступен')};
					self.render();
				});
		},

		/** Чат справки: выбрать роль, которую просила справка, если она открыта сотруднику. */
		applyDocsRole: function()
		{
			var want = this.record && this.record.docs ? this.record.role : '';
			var roles = this.info && this.info.roles ? this.info.roles : [];
			if(want && want !== this.info.role && roles.some(function(r) { return r.code === want; }))
			{
				this.chooseRole(want);
			}
		},

		storageKey: function()
		{
			return 'shef-aia-messages-' + chatKey(this.record);
		},

		loadMessages: function()
		{
			try
			{
				var list = JSON.parse(get(this.storageKey()) || '[]');
				return Array.isArray(list) ? list.filter(function(m) { return m && (m.role === 'user' || m.role === 'assistant') && typeof m.text === 'string' && !m.streaming; }) : [];
			}
			catch(e)
			{
				return [];
			}
		},

		saveMessages: function()
		{
			// Временное (идёт поток, отправка оценки, ошибка оценки) не храним
			put(this.storageKey(), JSON.stringify(this.messages.filter(function(m) { return !m.streaming; }).slice(-HISTORY_KEEP).map(function(m)
			{
				var copy = {};
				Object.keys(m).forEach(function(k) { if(k !== 'rating' && k !== 'rateError' && k !== 'askComment') { copy[k] = m[k]; } });
				return copy;
			})));
		},

		/** Показать / спрятать подтверждение «Очистить диалог?». */
		askClear: function(show)
		{
			this.confirmRow.hidden = !show;
		},

		clear: function()
		{
			this.requestId++;
			this.stopStream();
			this.busy = false;
			this.messages = [];
			this.lastError = '';
			drop(this.storageKey());
			this.render();
		},

		/** Сменить роль: сервер проверит право и запомнит выбор для этого чата. */
		chooseRole: function(code)
		{
			var self = this;
			if(!this.info || code === this.info.role) { return; }
			var previous = this.info.role;
			this.info.role = code;
			BX.ajax.runAction(ACTION + 'role', {data: {role: code, record: recordData(this.record), context: contextOf(this.record)}})
				.then(function(response)
				{
					self.info.role = response.data.role;
					self.info.examples = response.data.examples || [];
					self.render();
				}, function(response)
				{
					self.info.role = previous;
					self.lastError = errorText(response, 'Не удалось сменить роль');
					self.render();
				});
		},

		send: function(text)
		{
			var question = String(text || '').trim();
			if(!question || this.busy || !this.info || this.info.error) { return; }
			this.input.value = '';
			this.lastError = '';
			this.messages.push({role: 'user', text: question});
			this.saveMessages();
			this.ask();
		},

		/**
		 * Запрос ответа по текущей переписке (и для «Повторить»): поток — текст
		 * печатается по мере прихода. Отказ (до потока или посреди) — как раньше:
		 * недописанный ответ убираем, показываем ошибку и «Повторить».
		 */
		ask: function()
		{
			var self = this;
			var requestId = ++this.requestId;
			var message = null;
			var stale = function() { return requestId !== self.requestId; };
			this.stopStream();
			this.busy = true;
			this.startStatus();
			this.render();
			this.pending = NS.Stream.request(ACTION + 'stream', {
				messages: this.messages.map(function(m) { return {role: m.role, text: m.text}; }),
				record: recordData(this.record), context: contextOf(this.record),
				role: this.info && this.info.role ? this.info.role : ''
			}, {
				onStart: function(event)
				{
					if(stale()) { return; }
					message = {role: 'assistant', text: '', sources: event.sources || [], roleCode: String(event.role || ''), streaming: true};
					self.messages.push(message);
					self.stepStatus('start');
					self.scheduleRender();
				},
				onDelta: function(text)
				{
					if(stale() || !message) { return; }
					message.text += text;
					self.stepStatus('delta');
					self.scheduleRender();
				},
				onFinish: function(event)
				{
					if(stale() || !message) { return; }
					self.pending = null;
					self.busy = false;
					self.stepStatus('finish');
					delete message.streaming;
					message.cut = event.finishReason === 'length';
					self.saveMessages();
					self.render();
				},
				onError: function(response)
				{
					if(stale()) { return; }
					self.pending = null;
					self.busy = false;
					self.stepStatus('error');
					if(message)
					{
						self.messages = self.messages.filter(function(m) { return m !== message; });
					}
					self.lastError = errorText(response, 'Не удалось выполнить запрос');
					self.render();
				}
			});
		},

		/**
		 * Этапы ожидания (status.js, 1.3.2): история записи → база знаний →
		 * «Думаю…» (start потока) → печать (первый кусок).
		 */
		startStatus: function()
		{
			var self = this;
			var S = NS.ChatStatus;
			this.stopStatusTimer();
			this.status = S ? S.initial({record: !!(this.record && !this.record.docs), kb: !!(this.info && this.info.kb)}) : null;
			if(S)
			{
				this.statusTimer = setInterval(function() { self.stepStatus('tick'); }, S.TICK);
			}
		},

		stepStatus: function(event)
		{
			if(!this.status || !NS.ChatStatus) { return; }
			var before = NS.ChatStatus.label(this.status);
			this.status = NS.ChatStatus.next(this.status, event);
			if(this.status.phase !== 'wait') { this.stopStatusTimer(); }
			if(event === 'tick' && NS.ChatStatus.label(this.status) !== before) { this.scheduleRender(); }
		},

		stopStatusTimer: function()
		{
			if(this.statusTimer) { clearInterval(this.statusTimer); this.statusTimer = 0; }
		},

		stopStream: function()
		{
			this.stopStatusTimer();
			if(this.pending)
			{
				this.pending.abort();
				this.pending = null;
			}
		},

		scheduleRender: function()
		{
			var self = this;
			if(this.frame) { return; }
			var run = function() { self.frame = 0; self.render(); };
			this.frame = window.requestAnimationFrame ? window.requestAnimationFrame(run) : setTimeout(run, 50);
		},

		/**
		 * Оценка ответа «полезно / не помогло» (1.1.0). Вопрос — ближайший выше,
		 * роль — в которой ответили. Оценённый ответ помечаем в переписке.
		 */
		rate: function(index, rating, comment)
		{
			var self = this;
			var message = this.messages[index];
			if(!message || message.role !== 'assistant' || message.rated || message.rating) { return; }
			var question = '';
			for(var i = index - 1; i >= 0; i--)
			{
				if(this.messages[i].role === 'user') { question = this.messages[i].text; break; }
			}
			message.rating = rating;
			this.render();
			BX.ajax.runAction(ACTION + 'rate', {data: {
				rating: rating,
				role: message.roleCode || (this.info && this.info.role) || '',
				record: recordData(this.record), context: contextOf(this.record),
				question: question,
				answer: message.text,
				comment: comment || ''
			}}).then(function()
			{
				delete message.rating;
				message.rated = rating;
				self.saveMessages();
				self.render();
			}, function(response)
			{
				delete message.rating;
				message.rateError = errorText(response, 'Не удалось сохранить оценку');
				self.render();
			});
		},

		retry: function()
		{
			if(this.busy || !this.messages.length) { return; }
			if(this.messages[this.messages.length - 1].role === 'assistant')
			{
				this.messages.pop();
			}
			this.lastError = '';
			this.ask();
		},

		/** Ссылки ответа: свой портал — в слайдере, чужой сайт — в новой вкладке. */
		onLinkClick: function(e)
		{
			var link = e.target && e.target.closest ? e.target.closest('a[data-shef-link]') : null;
			if(!link) { return; }
			e.preventDefault();
			var url;
			try { url = new URL(link.getAttribute('href'), window.location.href); } catch(err) { return; }
			if(url.origin === window.location.origin && BX.SidePanel && BX.SidePanel.Instance)
			{
				BX.SidePanel.Instance.open(url.href, {cacheable: false});
			}
			else
			{
				window.open(url.href, '_blank', 'noopener');
			}
		},

		copy: function(text, button)
		{
			if(!navigator.clipboard) { return; }
			navigator.clipboard.writeText(text).then(function()
			{
				setIcon(button, 'check-m', 'Скопировано');
				setTimeout(function() { setIcon(button, 'o-copy', 'Копировать ответ'); }, 1500);
			}, function() {});
		},

		render: function()
		{
			var self = this;
			var info = this.info;
			var md = NS.Markdown;

			// Роли: только открытые сотруднику (сервер прислал уже отфильтрованные)
			BX.cleanNode(this.roleSelect);
			var roles = info && info.roles ? info.roles : [];
			roles.forEach(function(role)
			{
				var option = el('option', '', role.title);
				option.value = role.code;
				option.title = role.description || '';
				option.selected = info.role === role.code;
				self.roleSelect.appendChild(option);
			});
			this.roleRow.style.display = roles.length ? '' : 'none';
			this.roleSelect.disabled = this.busy;
			this.clearButton.style.visibility = this.messages.length ? 'visible' : 'hidden';
			if(!this.messages.length) { this.askClear(false); }
			this.disclaimer.textContent = 'Ответы ' + (info && info.model ? info.model : 'ИИ') + ' могут быть неточны, проверяйте важную информацию.';

			var box = this.scroller;
			BX.cleanNode(box);

			var welcome = el('div', 'shef-aia-welcome');
			welcome.appendChild(el('h2', 'shef-aia-welcome-title', this.record ? this.record.title : 'ИИ-помощник'));
			if(!info)
			{
				welcome.appendChild(el('p', '', 'Загружаю…'));
			}
			else if(info.error)
			{
				welcome.appendChild(el('p', 'shef-aia-error', info.error));
			}
			else if(!roles.length)
			{
				welcome.appendChild(el('p', 'shef-aia-error', 'Вам не открыта ни одна роль помощника — обратитесь к администратору портала.'));
			}
			else
			{
				welcome.appendChild(el('p', '', this.record && !this.record.docs
					? 'Отвечает по истории этой записи: звонки, письма, чаты, дела, комментарии' + (info.kb ? ', и по статьям базы знаний.' : '.') + ' Например:'
					: 'Общий чат: откройте «✦ Спросить ИИ» в строке записи, чтобы помощник ответил по её истории.' + (info.kb ? ' Статьи базы знаний — подбираются под вопрос.' : '') + ' Например:'));
				var list = el('ul', 'shef-aia-examples');
				(info.examples || []).forEach(function(question)
				{
					var item = el('li');
					var button = el('button', 'shef-aia-example', '«' + question + '»');
					button.type = 'button';
					button.addEventListener('click', function() { self.send(question); });
					item.appendChild(button);
					list.appendChild(item);
				});
				welcome.appendChild(list);
			}
			box.appendChild(welcome);

			this.messages.forEach(function(message, index)
			{
				if(message.role === 'user')
				{
					box.appendChild(el('div', 'shef-aia-msg shef-aia-msg-user', message.text));
					return;
				}
				var answer = el('div', 'shef-aia-msg shef-aia-msg-assistant');
				var body = el('div', 'shef-aia-md');
				body.innerHTML = md ? md.render(message.text) : BX.util.htmlspecialchars(message.text);
				answer.appendChild(body);
				if(message.cut)
				{
					answer.appendChild(el('p', 'shef-aia-cut', 'Ответ обрезан: модель упёрлась в лимит длины. Задайте вопрос уже или попросите продолжить.'));
				}
				if(message.sources && message.sources.length)
				{
					var sources = el('div', 'shef-aia-sources');
					sources.appendChild(el('div', 'shef-aia-sources-title', 'Статьи базы знаний:'));
					var ul = el('ul');
					message.sources.forEach(function(source)
					{
						var safe = md ? md.safeUrl(source.url) : null;
						var li = el('li');
						if(safe)
						{
							var a = el('a', '', source.title);
							a.href = safe;
							a.setAttribute('data-shef-link', '1');
							li.appendChild(a);
						}
						else
						{
							li.textContent = source.title;
						}
						ul.appendChild(li);
					});
					sources.appendChild(ul);
					answer.appendChild(sources);
				}
				if(message.streaming)
				{
					// Пока модель молчит — не пустой пузырь, а индикатор ниже
					if(message.text !== '') { box.appendChild(answer); }
					return;
				}
				var tools = el('div', 'shef-aia-tools');
				var copy = iconButton('shef-aia-tool', 'o-copy', 'Копировать ответ');
				copy.addEventListener('click', function() { self.copy(message.text, copy); });
				tools.appendChild(copy);
				if(index === self.messages.length - 1)
				{
					var again = iconButton('shef-aia-tool', 'o-refresh', 'Ответить заново');
					again.addEventListener('click', function() { self.retry(); });
					tools.appendChild(again);
				}
				self.renderRating(tools, message, index);
				answer.appendChild(tools);
				if(message.askComment && !message.rated)
				{
					answer.appendChild(self.commentForm(message, index));
				}
				if(message.rateError)
				{
					answer.appendChild(el('p', 'shef-aia-error', message.rateError));
				}
				box.appendChild(answer);
			});

			var waiting = this.busy ? (this.status && NS.ChatStatus ? NS.ChatStatus.label(this.status) : 'Думаю…') : '';
			if(waiting)
			{
				var thinking = el('div', 'shef-aia-thinking');
				thinking.setAttribute('role', 'status');
				thinking.appendChild(el('span', 'shef-aia-thinking-star', '✦'));
				thinking.appendChild(el('span', 'shef-aia-thinking-text', waiting));
				box.appendChild(thinking);
			}
			if(this.lastError && !this.busy)
			{
				var error = el('div', 'shef-aia-answer-error');
				error.appendChild(el('p', 'shef-aia-error', this.lastError));
				if(this.messages.length && this.messages[this.messages.length - 1].role === 'user')
				{
					var retry = el('button', 'ui-btn ui-btn-sm ui-btn-light-border', 'Повторить');
					retry.type = 'button';
					retry.addEventListener('click', function() { self.retry(); });
					error.appendChild(retry);
				}
				box.appendChild(error);
			}

			this.sendButton.disabled = this.busy || !info || !!info.error || !roles.length;
			box.scrollTop = box.scrollHeight;
		}
	};

	/**
	 * Оценка под ответом (1.3.2) — иконки «полезно» / «не помогло» с
	 * состоянием: выбранная подсвечена (aria-pressed), после оценки обе
	 * неактивны, подсказка — «Спасибо за оценку».
	 */
	Chat.renderRating = function(tools, message, index)
	{
		var self = this;
		var chosen = message.rated || (message.askComment ? 'down' : '');
		var make = function(code, icon, title, onClick)
		{
			var pressed = chosen === code;
			var button = iconButton('shef-aia-tool' + (pressed ? ' --active' : ''), icon, message.rated && pressed ? 'Спасибо за оценку' : title);
			button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
			button.disabled = !!message.rated || !!message.rating;
			button.addEventListener('click', onClick);
			tools.appendChild(button);
		};
		make('up', 'o-like', 'Полезно', function() { self.rate(index, 'up', ''); });
		make('down', 'o-dislike', 'Не помогло — напишите, что не так', function()
		{
			delete message.rateError;
			message.askComment = !message.askComment;
			self.render();
		});
	};

	/** «Не помогло»: что не так — по желанию, потом «Отправить». */
	Chat.commentForm = function(message, index)
	{
		var self = this;
		var form = el('div', 'shef-aia-rate-form');
		var input = el('textarea', 'shef-aia-rate-comment');
		input.rows = 2;
		input.maxLength = 1000;
		input.placeholder = 'Что не так с ответом? (по желанию)';
		var send = el('button', 'ui-btn ui-btn-xs ui-btn-light-border', 'Отправить');
		send.type = 'button';
		send.disabled = !!message.rating;
		send.addEventListener('click', function() { delete message.askComment; self.rate(index, 'down', input.value); });
		form.appendChild(input);
		form.appendChild(send);
		return form;
	};

	/**
	 * Страница внутри слайдера (IFRAME=Y, 1.3.0): своей панели во фрейме нет,
	 * чат открывается в верхнем окне. Расширение туда подгружается лениво —
	 * только по первому open()/toggle() или клику по кнопке (top.BX.load), один
	 * раз на верхнее окно, и запускается без автооткрытия (fromFrame).
	 *
	 * Для вложенных страниц без BX (справка shef.toolsai) в верхнем окне
	 * ставится лёгкая заглушка window.top.ShefAiChat (stub: true): она грузит
	 * расширение при первом вызове. Настоящий init() её заменяет.
	 */
	var Frame = {
		init: function(settings, topWindow)
		{
			var self = this;
			this.settings = settings;
			this.topWindow = topWindow || window.top;
			var api = function(name) { return function(rec) { self.top(function(chat) { chat[name](rec); }); }; };
			window.ShefAiChat = {
				open: api('open'),
				close: api('close'),
				toggle: api('toggle'),
				isOpen: function() { var t = self.topChat(); return !!t && t.isOpen(); },
				// Адрес карточки — этого фрейма, открываем в верхнем окне
				openCurrent: function() { var rec = currentRecord(); if(rec) { window.ShefAiChat.open(rec); } }
			};
			BX.addCustomEvent('ShefAiChat:open', function(rec) { window.ShefAiChat.open(rec); });
			this.installStub();
			var bind = function()
			{
				var button = document.getElementById(settings.button);
				if(!button || button.getAttribute('data-shef-bound')) { return; }
				button.setAttribute('data-shef-bound', '1');
				button.addEventListener('click', function(e) { e.preventDefault(); window.ShefAiChat.toggle(null); });
			};
			bind();
			BX.ready(bind);
		},

		/** Заглушка в верхнем окне — если там ещё нет ни чата, ни заглушки. */
		installStub: function()
		{
			var self = this;
			var top = this.topWindow;
			try
			{
				if(top.ShefAiChat) { return; }
				var call = function(name) { return function(rec) { self.top(function(chat) { chat[name](rec); }); }; };
				top.ShefAiChat = {stub: true, open: call('open'), close: call('close'), toggle: call('toggle'), isOpen: function() { return false; }};
			}
			catch(e) {}
		},

		/** Настоящий чат верхнего окна (не заглушка) или null. */
		topChat: function()
		{
			try
			{
				var chat = this.topWindow.ShefAiChat;
				return chat && !chat.stub && chat.toggle ? chat : null;
			}
			catch(e) { return null; }
		},

		/** Вызвать с чатом верхнего окна; нет — подгрузить расширение один раз. */
		top: function(callback)
		{
			var chat = this.topChat();
			if(chat) { callback(chat); return; }
			var top = this.topWindow;
			var settings = this.settings;
			var topBX;
			try { topBX = top.BX; } catch(e) { return; }
			if(!topBX || !topBX.load || !settings.assets) { return; }
			var self = this;
			if(top.__shefAiChatQueue)
			{
				top.__shefAiChatQueue.push(callback);
				return;
			}
			top.__shefAiChatQueue = [callback];
			topBX.load(settings.assets, function()
			{
				var ns = topBX.Shef && topBX.Shef.ToolsAiAssistant;
				if(ns && ns.Chat) { ns.Chat.init(Object.assign({}, settings, {fromFrame: true})); }
				var queue = top.__shefAiChatQueue || [];
				top.__shefAiChatQueue = null;
				var ready = self.topChat();
				if(ready) { queue.forEach(function(cb) { cb(ready); }); }
			});
		}
	};

	NS.Chat = {
		init: function(settings) { Chat.init(settings); },
		/** Для тестов и отладки: ключ чата — как на сервере. */
		chatKey: chatKey,
		/** Для тестов: посредник фрейма (Frame.init(settings, topWindow)). */
		Frame: Frame,
		/** Для тестов: что откроет window.ShefAiChat.open() (запись CRM или справка). */
		normalizeRecord: normalizeRecord,
		/** Для тестов: запись CRM по адресу карточки (1.3.2). */
		recordFromPath: recordFromPath
	};
})();
