/**
 * Страница «Оценки ответов» (1.4.0): окно оценки, групповые действия грида,
 * выгрузка кейсов проверки промптов.
 *
 * Вёрстка окна — detailHtml(): весь текст сотрудника экранируется, ответ
 * модели — Markdown.render() окна чата (сырой HTML модели не исполняется,
 * ссылки — только «/…» и http(s)). В браузере — BX.Shef.ToolsAiAssistant.Feedback,
 * в тесте (node, tests/feedback_test.mjs) — module.exports.
 */
(function(root)
{
	'use strict';

	var state = {gridId: '', filterId: '', canEdit: false, statuses: {}, rows: {}};
	var popup = null;

	/** Адрес — только путь своего портала («/…», не «//…»). */
	function localUrl(url)
	{
		return typeof url === 'string' && /^\/(?![\/\\])[^\s<>"']*$/.test(url) ? url : null;
	}

	/**
	 * HTML окна оценки.
	 *
	 * @param {object} row строка clientRows() страницы
	 * @param {{render: function, escape: function}} md markdown.js
	 * @param {object} statuses код статуса => подпись
	 * @param {boolean} canEdit можно ли писать «что должно было быть»
	 */
	function detailHtml(row, md, statuses, canEdit)
	{
		var e = md.escape;
		var recordUrl = localUrl(row.recordUrl);
		var promptUrl = localUrl(row.promptUrl);
		var html = [];
		html.push('<div class="shef-aia-fb-detail">');
		html.push('<div class="shef-aia-fb-meta">' + e(row.created) + ' · ' + e(row.user) + ' · ' + e(row.roleTitle)
			+ (promptUrl ? ' (<a href="' + e(promptUrl) + '" target="_blank">править промпт роли</a>)' : '')
			+ ' · ' + (recordUrl ? '<a href="' + e(recordUrl) + '" target="_blank">' + e(row.chat) + '</a>' : e(row.chat))
			+ ' · ' + (row.rating === 1 ? '👍 ' : '👎 ') + e(row.ratingTitle)
			+ ' · статус: <b>' + e(statuses[row.status] || row.status) + '</b></div>');
		html.push('<h4>Вопрос</h4><div class="shef-aia-fb-text">' + e(row.question) + '</div>');
		html.push('<h4>Ответ помощника</h4><div class="shef-aia-fb-answer">' + md.render(row.answer) + '</div>');
		if(row.comment)
		{
			html.push('<h4>Комментарий сотрудника</h4><div class="shef-aia-fb-text">' + e(row.comment) + '</div>');
		}
		html.push('<h4>Что должно было быть</h4>');
		if(canEdit)
		{
			html.push('<textarea class="shef-aia-fb-expected" placeholder="По факту на строку: что обязан сказать ответ.\nВарианты одного факта — через |, например: 20 | двадцать\nСтрока с ! — чего в ответе быть не должно">' + e(row.expected) + '</textarea>');
			html.push('<div class="shef-aia-fb-note">Из этих строк выгрузка соберёт факты кейса проверки — их отмечает судья при прогоне проверки промптов shef.toolsai. Имена, телефоны и почту выгрузка заменит маркерами.</div>');
		}
		else
		{
			html.push(row.expected ? '<div class="shef-aia-fb-text">' + e(row.expected) + '</div>' : '<div class="shef-aia-fb-empty">не заполнено</div>');
		}
		html.push('</div>');

		return html.join('');
	}

	/** Имя файла выгрузки: assistant-feedback-cases-2026-10-17.json. */
	function fileName(date)
	{
		var pad = function(n) { return (n < 10 ? '0' : '') + n; };

		return 'assistant-feedback-cases-' + date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + '.json';
	}

	/** Итог выгрузки словами. */
	function exportSummary(result)
	{
		var exported = (result.exported || []).length;
		var skipped = result.skipped || {};
		var reasons = result.reasons || {};
		var byReason = {};
		Object.keys(skipped).forEach(function(id)
		{
			var reason = reasons[skipped[id]] || skipped[id];
			(byReason[reason] = byReason[reason] || []).push('№' + id);
		});
		var parts = ['Кейсов выгружено: ' + exported + '.'];
		Object.keys(byReason).forEach(function(reason)
		{
			parts.push('Пропущено (' + reason + '): ' + byReason[reason].join(', ') + '.');
		});

		return parts.join(' ');
	}

	function grid()
	{
		return root.BX && BX.Main && BX.Main.gridManager ? BX.Main.gridManager.getInstanceById(state.gridId) : null;
	}

	function selectedIds()
	{
		var instance = grid();

		return instance ? instance.getRows().getSelectedIds() : [];
	}

	function notify(text)
	{
		if(BX.UI && BX.UI.Notification && BX.UI.Notification.Center)
		{
			BX.UI.Notification.Center.notify({content: BX.util.htmlspecialchars(text), autoHideDelay: 6000});
		}
		else
		{
			root.alert(text);
		}
	}

	function errorText(response)
	{
		var errors = response && response.errors ? response.errors : [];

		return errors.length ? errors.map(function(error) { return error.message; }).join('; ') : 'Не удалось выполнить действие';
	}

	/** Перечитать страницу: сводка по ролям и данные окна — не часть грида. */
	function reload()
	{
		root.location.reload();
	}

	function run(action, data)
	{
		return BX.ajax.runAction('shef:toolsaiassistant.feedback.' + action, {data: data});
	}

	var api = {
		detailHtml: detailHtml,
		fileName: fileName,
		exportSummary: exportSummary,
		localUrl: localUrl,

		init: function(options)
		{
			state.gridId = options.gridId;
			state.filterId = options.filterId;
			state.canEdit = !!options.canEdit;
			state.statuses = options.statuses || {};
			state.rows = options.rows || {};
			BX.bind(document, 'click', function(event)
			{
				var link = event.target && event.target.closest ? event.target.closest('.shef-aia-fb-open') : null;
				if(link)
				{
					event.preventDefault();
					api.open(parseInt(link.getAttribute('data-id'), 10));
				}
			});
			// Сводка над гридом считается по фильтру — после смены фильтра перечитать страницу
			BX.addCustomEvent('BX.Main.Filter:apply', function(filterId)
			{
				if(filterId === state.filterId)
				{
					root.location.reload();
				}
			});
		},

		open: function(id)
		{
			var row = state.rows[id];
			if(!row)
			{
				return;
			}
			if(popup)
			{
				popup.destroy();
			}
			var buttons = [];
			if(state.canEdit)
			{
				buttons.push(new BX.PopupWindowButton({
					text: 'Сохранить',
					className: 'ui-btn ui-btn-success',
					events: {click: function()
					{
						var area = popup.getContentContainer().querySelector('.shef-aia-fb-expected');
						var value = area ? area.value : '';
						run('expect', {id: id, expected: value}).then(function(response)
						{
							row.expected = response.data.expected;
							row.status = response.data.status;
							popup.close();
							notify('Сохранено');
							reload();
						}, function(response) { notify(errorText(response)); });
					}},
				}));
			}
			buttons.push(new BX.PopupWindowButton({text: 'Закрыть', className: 'ui-btn ui-btn-light-border', events: {click: function() { popup.close(); }}}));
			popup = new BX.PopupWindow('shef-aia-fb-detail', null, {
				titleBar: 'Оценка №' + id,
				content: detailHtml(row, BX.Shef.ToolsAiAssistant.Markdown, state.statuses, state.canEdit),
				width: 760,
				closeIcon: true,
				closeByEsc: true,
				overlay: {opacity: 30},
				autoHide: false,
				buttons: buttons,
			});
			popup.show();
		},

		status: function(status)
		{
			var ids = selectedIds();
			if(!ids.length)
			{
				notify('Отметьте оценки в таблице');
				return;
			}
			run('status', {ids: ids, status: status}).then(function(response)
			{
				notify('Изменено оценок: ' + response.data.updated);
				reload();
			}, function(response) { notify(errorText(response)); });
		},

		export: function()
		{
			var ids = selectedIds();
			if(!ids.length)
			{
				notify('Отметьте оценки в таблице');
				return;
			}
			run('export', {ids: ids}).then(function(response)
			{
				var result = response.data;
				if((result.exported || []).length)
				{
					var blob = new Blob([result.json + '\n'], {type: 'application/json'});
					var link = document.createElement('a');
					link.href = URL.createObjectURL(blob);
					link.download = fileName(new Date());
					document.body.appendChild(link);
					link.click();
					setTimeout(function() { URL.revokeObjectURL(link.href); link.remove(); }, 1000);
				}
				notify(exportSummary(result));
				reload();
			}, function(response) { notify(errorText(response)); });
		},
	};

	if(typeof module === 'object' && module.exports)
	{
		module.exports = api;
	}
	else if(root.BX && root.BX.namespace)
	{
		root.BX.namespace('BX.Shef.ToolsAiAssistant').Feedback = api;
	}
})(typeof window !== 'undefined' ? window : this);
