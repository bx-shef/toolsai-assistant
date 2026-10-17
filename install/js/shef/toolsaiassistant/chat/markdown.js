/**
 * Ответ модели — в HTML для окна чата. Сначала экранируется ВЕСЬ текст
 * (сырой HTML модели в окне не исполняется), потом размечается то немногое,
 * что нужно ответу: абзацы, списки, **жирный**, *курсив*, `код`, блоки ```,
 * ссылки [текст](адрес) — только на путь своего портала («/…») или http(s).
 *
 * Виджеты (1.7.0): блок ```widget с JSON {"type": "…", …} не печатается как
 * код — на его месте пустой <div data-shef-widget="N">, а разобранный JSON
 * отдаётся в renderFull().widgets; рисует его реестр виджетов (widgets.js)
 * своими DOM-узлами, без innerHTML. Незакрытый блок (ответ ещё печатается) —
 * заглушка «Готовлю виджет…»; негодный JSON — обычный блок кода.
 *
 * Без зависимостей: в браузере — BX.Shef.ToolsAiAssistant.Markdown, в тесте
 * (node, tests/markdown_test.mjs) — module.exports.
 */
(function(root)
{
	'use strict';

	function escape(text)
	{
		return String(text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	/** Адрес ссылки: путь своего портала или http(s); остальное (javascript:, data:, //чужой) — нет. */
	function safeUrl(url)
	{
		url = String(url).trim();
		if(/^\/(?![\/\\])[^\s<>"']*$/.test(url))
		{
			return url;
		}
		if(/^https?:\/\/[^\s<>"']+$/i.test(url))
		{
			return url;
		}
		return null;
	}

	/** Строка без блоков: код, ссылки, жирный, курсив. Вход — уже экранированный текст. */
	function inline(text)
	{
		var codes = [];
		text = text.replace(/`([^`]+)`/g, function(m, code)
		{
			codes.push('<code>' + code + '</code>');
			return '\u0000' + (codes.length - 1) + '\u0000';
		});
		text = text.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function(m, label, url)
		{
			// Адрес уже экранирован: &amp; вернуть в &, чтобы проверить и экранировать один раз
			var raw = url.replace(/&amp;/g, '&');
			var safe = safeUrl(raw);
			return safe === null ? label : '<a href="' + escape(safe) + '" data-shef-link="1">' + label + '</a>';
		});
		text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
		text = text.replace(/(^|[^*\w])\*([^*\s][^*]*)\*(?!\w)/g, '$1<em>$2</em>');
		return text.replace(/\u0000(\d+)\u0000/g, function(m, i) { return codes[+i]; });
	}

	/** Разбор JSON виджета: {type, data} или null (тип — латиница, цифры, «.», «-», «_», «:»). */
	function parseWidget(json)
	{
		var data;
		try { data = JSON.parse(json); } catch(e) { return null; }
		if(!data || typeof data !== 'object' || Array.isArray(data)) { return null; }
		var type = typeof data.type === 'string' ? data.type.trim().toLowerCase() : '';
		return /^[a-z0-9][a-z0-9_.:-]{0,60}$/.test(type) ? {type: type, data: data} : null;
	}

	/** Markdown ответа -> HTML. */
	function render(source)
	{
		return renderFull(source).html;
	}

	/** Markdown ответа -> {html, widgets}: widgets[N] — для <div data-shef-widget="N">. */
	function renderFull(source)
	{
		var rawLines = String(source || '').replace(/\r\n?/g, '\n').split('\n');
		var lines = rawLines.map(escape);
		var widgets = [];
		var widget = null;
		var html = [];
		var paragraph = [];
		var list = null;
		var code = null;

		function flushParagraph()
		{
			if(paragraph.length)
			{
				html.push('<p>' + paragraph.map(inline).join('<br>') + '</p>');
				paragraph = [];
			}
		}
		function flushList()
		{
			if(list)
			{
				html.push('<' + list.tag + '>' + list.items.map(function(item) { return '<li>' + inline(item) + '</li>'; }).join('') + '</' + list.tag + '>');
				list = null;
			}
		}

		for(var i = 0; i < lines.length; i++)
		{
			var line = lines[i];
			if(widget !== null)
			{
				if(/^\s*```\s*$/.test(line))
				{
					var parsed = parseWidget(widget.raw.join('\n'));
					if(parsed)
					{
						widgets.push(parsed);
						html.push('<div class="shef-aia-widget" data-shef-widget="' + (widgets.length - 1) + '"></div>');
					}
					else
					{
						html.push('<pre><code>' + widget.lines.join('\n') + '</code></pre>');
					}
					widget = null;
				}
				else
				{
					widget.raw.push(rawLines[i]);
					widget.lines.push(line);
				}
				continue;
			}
			if(code === null && /^\s*```\s*widget\s*$/i.test(line))
			{
				flushParagraph();
				flushList();
				widget = {raw: [], lines: []};
				continue;
			}
			if(code !== null)
			{
				if(/^\s*```/.test(line))
				{
					html.push('<pre><code>' + code.join('\n') + '</code></pre>');
					code = null;
				}
				else
				{
					code.push(line);
				}
				continue;
			}
			if(/^\s*```/.test(line))
			{
				flushParagraph();
				flushList();
				code = [];
				continue;
			}
			var bullet = /^\s*[-*•]\s+(.*)$/.exec(line);
			var number = /^\s*\d+[.)]\s+(.*)$/.exec(line);
			if(bullet || number)
			{
				flushParagraph();
				var tag = bullet ? 'ul' : 'ol';
				if(list && list.tag !== tag)
				{
					flushList();
				}
				list = list || {tag: tag, items: []};
				list.items.push((bullet || number)[1]);
				continue;
			}
			if(line.trim() === '')
			{
				flushParagraph();
				flushList();
				continue;
			}
			// Заголовки markdown роль просит не писать; написала — показываем жирной строкой
			var heading = /^\s*#{1,6}\s+(.*)$/.exec(line);
			flushList();
			paragraph.push(heading ? '**' + heading[1] + '**' : line);
		}
		if(code !== null)
		{
			html.push('<pre><code>' + code.join('\n') + '</code></pre>');
		}
		flushParagraph();
		flushList();
		if(widget !== null)
		{
			// Ответ ещё печатается: JSON виджета не показываем сырым
			html.push('<div class="shef-aia-widget --pending">Готовлю виджет…</div>');
		}

		return {html: html.join(''), widgets: widgets};
	}

	var api = {render: render, renderFull: renderFull, parseWidget: parseWidget, escape: escape, safeUrl: safeUrl};

	if(typeof module === 'object' && module.exports)
	{
		module.exports = api;
	}
	else if(root.BX && root.BX.namespace)
	{
		root.BX.namespace('BX.Shef.ToolsAiAssistant').Markdown = api;
	}
})(typeof window !== 'undefined' ? window : this);
