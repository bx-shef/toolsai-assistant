/**
 * Размер и место панели чата (1.3.2) — чистые функции, без DOM
 * (tests/layout_test.mjs).
 *
 * Панель лежит поверх страницы и слайдеров (overlay), страницу не сдвигает.
 * Справа оставляем место полосе мессенджера портала (#right-bar шаблона):
 * панель встаёт левее неё, полоса остаётся у края. Слайдер BX.SidePanel
 * считает свою ширину сам (intranet/sidepanel/air: getRightBoundary() — по
 * #right-bar), мы в этот расчёт не вмешиваемся.
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

		/** Ширина при растягивании мышью: от курсора до левого края полосы мессенджера. */
		dragWidth: function(viewport, inset, clientX)
		{
			return Math.round(viewport - inset - clientX);
		}
	};

	NS.ChatLayout = Layout;
})();
