/**
 * Размер и место панели чата — чистые функции, без DOM (tests/layout_test.mjs).
 *
 * С 1.9.0 два места:
 * - «air» — правая колонка шаблона Air (#app__right-panel), как чат Марты:
 *   страницу сужает сетка шаблона, слайдер открывается левее колонки
 *   (intranet/sidepanel/air: getRightBoundary() — по #app__right-panel).
 *   Ширину ведёт ядро (RightPanel: --air-right-panel-width, своя ручка,
 *   CUserOptions intranet/right_panel_width), здесь не считаем.
 * - «overlay» — шаблон не Air или узкий экран (меньше 768, как в ядре —
 *   там колонка скрыта): фиксированная панель у правого края левее полосы
 *   мессенджера (#right-bar). На широком экране колонка контента старого
 *   шаблона сужается на ширину панели (push()), слайдер — поверх чата.
 */
(function()
{
	'use strict';

	var NS = BX.namespace('BX.Shef.ToolsAiAssistant');

	var Layout = {
		/** Ширина по умолчанию: 36% окна, но не уже 420 и не шире 480. */
		DEFAULT_MIN: 420,
		DEFAULT_MAX: 480,
		DEFAULT_RATIO: 0.36,
		/** Уже этого (свободного места) — узкий экран: панель на всю ширину. */
		NARROW: 760,
		/** Уже этого окна правой колонки Air нет (root.css ядра: max-width 767px). */
		AIR_MIN: 768,
		/** Сколько оставить слева при ручном растягивании (полоса левого меню). */
		LEFT_GAP: 65,
		/** Полоса справа шире этого — не полоса мессенджера, не учитываем. */
		INSET_MAX: 200,

		/**
		 * Отступ справа под полосу мессенджера: полоса видна и прижата к правому
		 * краю окна — её ширина, иначе 0.
		 *
		 * @param {number} viewport ширина окна
		 * @param {?{left: number, right: number, width: number}} rect getBoundingClientRect() полосы
		 */
		inset: function(viewport, rect)
		{
			if(!rect || !(rect.width > 0) || rect.right < viewport - 2)
			{
				return 0;
			}
			var inset = Math.round(viewport - rect.left);

			return inset > 0 && inset <= this.INSET_MAX ? inset : 0;
		},

		/** Ширина по умолчанию для окна: clamp(420, 36%, 480). */
		defaultWidth: function(viewport)
		{
			return Math.max(this.DEFAULT_MIN, Math.min(this.DEFAULT_MAX, Math.round(viewport * this.DEFAULT_RATIO)));
		},

		/**
		 * Ширина и место панели.
		 *
		 * @param {number} viewport ширина окна
		 * @param {number} inset отступ справа (inset())
		 * @param {*} saved ширина, которую сотрудник выставил мышью (или пусто)
		 * @param {{min: number, max: number}} limits пределы ручной ширины
		 * @return {{width: number, right: number, narrow: boolean}}
		 */
		compute: function(viewport, inset, saved, limits)
		{
			viewport = Math.max(0, Math.round(Number(viewport) || 0));
			inset = Math.max(0, Math.min(Math.round(Number(inset) || 0), viewport));
			var free = viewport - inset;
			if(free < this.NARROW)
			{
				return {width: free, right: inset, narrow: true};
			}
			var min = limits && limits.min > 0 ? limits.min : 320;
			var max = Math.min(limits && limits.max > 0 ? limits.max : 720, free - this.LEFT_GAP);
			max = Math.max(min, max);
			var width = parseInt(saved, 10);
			if(isNaN(width))
			{
				width = this.defaultWidth(viewport);
			}

			return {width: Math.max(min, Math.min(max, width)), right: inset, narrow: false};
		},

		/**
		 * Где показать панель: «air» — в правой колонке шаблона (есть
		 * публичный BX.Intranet.Bitrix24.Template.getRightPanel() и окно не
		 * уже 768), иначе «overlay».
		 *
		 * @param {number} viewport ширина окна
		 * @param {boolean} hasAirPanel есть правая колонка шаблона Air
		 */
		mode: function(viewport, hasAirPanel)
		{
			return hasAirPanel && Number(viewport) >= this.AIR_MIN ? 'air' : 'overlay';
		},

		/**
		 * На сколько сузить колонку контента старого шаблона: ширина панели и
		 * полосы справа; узкий экран (панель на всю ширину) — 0.
		 *
		 * @param {{width: number, right: number, narrow: boolean}} layout compute()
		 */
		push: function(layout)
		{
			return layout && !layout.narrow ? Math.max(0, layout.width + layout.right) : 0;
		},

		/** Ширина при растягивании мышью: от курсора до левого края полосы мессенджера. */
		dragWidth: function(viewport, inset, clientX)
		{
			return Math.round(viewport - inset - clientX);
		}
	};

	NS.ChatLayout = Layout;
})();
