/**
 * Ответ модели — в HTML для окна чата. Сначала экранируется ВЕСЬ текст
 * (сырой HTML модели в окне не исполняется), потом размечается то немногое,
 * что нужно ответу: абзацы, списки, **жирный**, *курсив*, `код`, блоки ```,
 * ссылки [текст](адрес) — только на путь своего портала («/…») или http(s).
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

	/** Markdown ответа -> HTML. */
	function render(source)
	{
		var lines = escape(String(source || '').replace(/\r\n?/g, '\n')).split('\n');
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

		return html.join('');
	}

	var api = {render: render, escape: escape, safeUrl: safeUrl};

	if(typeof module === 'object' && module.exports)
	{
		module.exports = api;
	}
	else if(root.BX && root.BX.namespace)
	{
		root.BX.namespace('BX.Shef.ToolsAiAssistant').Markdown = api;
	}
})(typeof window !== 'undefined' ? window : this);
