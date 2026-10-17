/**
 * Поток ответа помощника (1.1.0): запрос к действию
 * shef:toolsaiassistant.chat.stream и разбор событий text/event-stream
 * (сервер — Chat\StreamEvents: «data: {json}» и пустая строка).
 *
 * BX.ajax.runAction поток не отдаёт по кускам — поэтому fetch на тот же
 * адрес ядра /bitrix/services/main/ajax.php с sessid (заголовок
 * X-Bitrix-Csrf-Token, как у runAction). Отказ до потока сервер присылает
 * обычным JSON ajax ({status: 'error', errors}) — его отдаём в onError как
 * есть.
 *
 * Без зависимостей: в браузере — BX.Shef.ToolsAiAssistant.Stream, в тесте
 * (node, tests/stream_test.mjs) — module.exports.
 */
(function(root)
{
	'use strict';

	/**
	 * Дописать кусок к буферу и вынуть готовые события.
	 * @return {{events: Array<Object>, rest: string}}
	 */
	function feed(buffer, chunk)
	{
		var text = (buffer + chunk).replace(/\r\n/g, '\n');
		var blocks = text.split('\n\n');
		var rest = blocks.pop();
		var events = [];
		blocks.forEach(function(block)
		{
			block.split('\n').forEach(function(line)
			{
				if(line.indexOf('data: ') !== 0) { return; }
				try
				{
					var event = JSON.parse(line.slice(6));
					if(event && typeof event.type === 'string') { events.push(event); }
				}
				catch(e) {}
			});
		});
		return {events: events, rest: rest};
	}

	/** Плоские поля формы из вложенного объекта: messages[0][role]=user. */
	function formBody(data, prefix, out)
	{
		out = out || [];
		Object.keys(data).forEach(function(key)
		{
			var name = prefix ? prefix + '[' + key + ']' : key;
			var value = data[key];
			if(value !== null && typeof value === 'object')
			{
				formBody(value, name, out);
			}
			else if(value !== undefined && value !== null)
			{
				out.push(encodeURIComponent(name) + '=' + encodeURIComponent(String(value)));
			}
		});
		return out;
	}

	/**
	 * Запросить ответ потоком.
	 * handlers: onStart(event), onDelta(text), onFinish(event), onError(response) — response в форме ошибки runAction.
	 * @return {{abort: function}}
	 */
	function request(action, data, handlers)
	{
		var BXg = root.BX;
		var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
		var done = false;
		function fail(message, code)
		{
			if(done) { return; }
			done = true;
			handlers.onError({status: 'error', errors: [{message: message, code: code || 'network'}]});
		}
		var url = '/bitrix/services/main/ajax.php?action=' + encodeURIComponent(action)
			+ (BXg && BXg.message && BXg.message('SITE_ID') ? '&SITE_ID=' + encodeURIComponent(BXg.message('SITE_ID')) : '');
		var sessid = BXg && BXg.bitrix_sessid ? BXg.bitrix_sessid() : '';
		root.fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			signal: controller ? controller.signal : undefined,
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Bitrix-Csrf-Token': sessid},
			body: formBody(Object.assign({sessid: sessid}, data)).join('&')
		}).then(function(response)
		{
			var type = response.headers.get('Content-Type') || '';
			if(type.indexOf('text/event-stream') === -1)
			{
				// Отказ до потока — штатный JSON ajax с ошибками
				return response.json().then(function(json)
				{
					if(json && json.errors && json.errors.length) { done = true; handlers.onError(json); }
					else { fail('Не удалось выполнить запрос'); }
				}, function() { fail('Не удалось выполнить запрос'); });
			}
			var reader = response.body.getReader();
			var decoder = new TextDecoder('utf-8');
			var buffer = '';
			function pump()
			{
				return reader.read().then(function(step)
				{
					var parsed = feed(buffer, step.done ? decoder.decode() + '\n\n' : decoder.decode(step.value, {stream: true}));
					buffer = parsed.rest;
					parsed.events.forEach(function(event)
					{
						if(done) { return; }
						if(event.type === 'start') { handlers.onStart(event); }
						else if(event.type === 'delta') { handlers.onDelta(String(event.text || '')); }
						else if(event.type === 'finish') { done = true; handlers.onFinish(event); }
						else if(event.type === 'error') { fail(String(event.message || 'Не удалось получить ответ'), event.code); }
					});
					if(step.done)
					{
						fail('Ответ модели оборвался — спросите ещё раз', 'model');
						return;
					}
					return pump();
				});
			}
			return pump();
		}).catch(function(e)
		{
			if(e && e.name === 'AbortError') { done = true; return; }
			fail('Нет связи с порталом — проверьте сеть и повторите');
		});
		return {abort: function() { done = true; if(controller) { controller.abort(); } }};
	}

	var api = {feed: feed, formBody: formBody, request: request};

	if(typeof module !== 'undefined' && module.exports)
	{
		module.exports = api;
	}
	else if(root.BX && root.BX.namespace)
	{
		root.BX.namespace('BX.Shef.ToolsAiAssistant').Stream = api;
	}
})(typeof window !== 'undefined' ? window : globalThis);
