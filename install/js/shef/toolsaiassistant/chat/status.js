/**
 * Этапы ожидания ответа (1.4.0) — чистые функции, без DOM
 * (tests/status_test.mjs). Как в чате shef.toolsai до 1.50.0
 * (HdChatOverlay.vue: «Ищу ответ среди N статей», «Думаю…»), но по этапам
 * нашего сценария (Chat\AnswerService): история записи → база знаний →
 * модель → печать.
 *
 * Пока сервер готовит ответ (история записи и статьи — до потока), этапы
 * сменяются по таймеру ('tick'), но не дальше последнего подготовительного:
 * «Думаю…» — только по событию start потока (модель позвали). Первый кусок
 * (delta) — печать: индикатор гаснет.
 */
(function()
{
	'use strict';

	var NS = BX.namespace('BX.Shef.ToolsAiAssistant');

	var TEXT = {
		record: 'Читаю историю записи…',
		kb: 'Ищу в базе знаний…',
		model: 'Думаю…'
	};

	var Status = {
		TEXT: TEXT,
		/** Шаг таймера этапов подготовки, мс. */
		TICK: 1500,

		/** Этапы по чату: запись CRM — история; база знаний подключена — статьи; всегда — модель. */
		plan: function(options)
		{
			var stages = [];
			if(options && options.record) { stages.push('record'); }
			if(options && options.kb) { stages.push('kb'); }
			stages.push('model');
			return stages;
		},

		initial: function(options)
		{
			var stages = this.plan(options);
			// Без подготовки (общий чат без базы) сразу «Думаю…»
			return {phase: 'wait', stages: stages, index: 0};
		},

		/**
		 * Следующее состояние.
		 * @param {{phase: string, stages: string[], index: number}} state
		 * @param {string} event tick | start | delta | finish | error
		 */
		next: function(state, event)
		{
			var s = {phase: state.phase, stages: state.stages, index: state.index};
			var model = s.stages.length - 1;
			if(event === 'tick')
			{
				if(s.phase === 'wait' && s.index < model - 1) { s.index++; }
			}
			else if(event === 'start')
			{
				if(s.phase === 'wait') { s.index = model; }
			}
			else if(event === 'delta')
			{
				if(s.phase === 'wait') { s.phase = 'typing'; }
			}
			else if(event === 'finish' || event === 'error')
			{
				s.phase = event === 'finish' ? 'done' : 'error';
			}
			return s;
		},

		/** Стадия сейчас (record | kb | model) или '' — ждать нечего. */
		stage: function(state)
		{
			return state && state.phase === 'wait' ? state.stages[state.index] : '';
		},

		/** Текст индикатора или '' — индикатор не показывать. */
		label: function(state)
		{
			var stage = this.stage(state);
			return stage ? TEXT[stage] : '';
		}
	};

	NS.ChatStatus = Status;
})();
