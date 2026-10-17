/**
 * Поток ответа в окне чата (install/js/.../chat/stream.js): разбор событий
 * text/event-stream из кусков любой длины (кусок сети может разрезать событие
 * и даже букву — буквы режет TextDecoder, события — feed()), тело формы для
 * ядра (messages[0][role]=user) и ход запроса на подменённом fetch: куски по
 * порядку, отказ до потока JSON-ом, обрыв без finish — ошибка.
 * Гоняет build.sh: node tests/stream_test.mjs.
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const stream = require(path.join(root, 'install/js/shef/toolsaiassistant/chat/stream.js'));

let count = 0;
const errors = [];
function same(what, actual, expected) {
  count++;
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  if (a === e) {
    console.log(`  OK   ${what}`);
  } else {
    errors.push(what);
    console.log(`  FAIL ${what}: получено ${a}, ожидалось ${e}`);
  }
}

console.log('\nразбор событий');
const body = 'data: {"type":"start","role":"r","sources":[]}\n\ndata: {"type":"delta","text":"При\\n\\nвет"}\n\ndata: {"type":"finish","finishReason":"stop"}\n\n';
let buffer = '';
const got = [];
for (let i = 0; i < body.length; i += 7) {
  const parsed = stream.feed(buffer, body.slice(i, i + 7));
  buffer = parsed.rest;
  got.push(...parsed.events);
}
same('куски по 7 знаков — те же три события', got.map((e) => e.type), ['start', 'delta', 'finish']);
same('перевод строки внутри текста цел', got[1].text, 'При\n\nвет');
same('мусор и не-data строки — мимо', stream.feed('', 'event: x\n\ndata: {oops\n\ndata: {"type":"delta","text":"a"}\n\n').events.length, 1);
same('незаконченное событие ждёт следующего куска', stream.feed('', 'data: {"type":"delta"').rest, 'data: {"type":"delta"');

console.log('\nтело формы');
same('вложенное — как ждёт ядро', stream.formBody({messages: [{role: 'user', text: 'Привет & пока'}], record: {}, role: ''}).join('&'),
  'messages%5B0%5D%5Brole%5D=user&messages%5B0%5D%5Btext%5D=%D0%9F%D1%80%D0%B8%D0%B2%D0%B5%D1%82%20%26%20%D0%BF%D0%BE%D0%BA%D0%B0&role=');

console.log('\nзапрос');
function fakeFetch(type, chunks, json) {
  return async (url, options) => {
    fakeFetch.last = {url, options};
    const enc = new TextEncoder();
    let i = 0;
    return {
      headers: {get: () => type},
      json: async () => json,
      body: {getReader: () => ({read: async () => (i < chunks.length ? {done: false, value: enc.encode(chunks[i++])} : {done: true})})},
    };
  };
}
function run(fetchImpl) {
  globalThis.fetch = fetchImpl;
  globalThis.BX = {bitrix_sessid: () => 'abc', message: () => 's1'};
  return new Promise((resolve) => {
    const log = [];
    stream.request('shef:toolsaiassistant.chat.stream', {messages: [{role: 'user', text: '?'}]}, {
      onStart: () => log.push('start'),
      onDelta: (t) => log.push('delta:' + t),
      onFinish: (e) => { log.push('finish:' + e.finishReason); resolve(log); },
      onError: (r) => { log.push('error:' + r.errors[0].message); resolve(log); },
    });
  });
}
// «П» в UTF-8 — два байта; режем событие посередине
const ok = await run(fakeFetch('text/event-stream; charset=utf-8', ['data: {"type":"start"}\n\ndata: {"type":"delta","text":"Пр', 'и"}\n\ndata: {"type":"finish","finishReason":"length"}\n\n']));
same('поток: start, кусок, finish', ok, ['start', 'delta:При', 'finish:length']);
same('sessid — в заголовке и в теле, сайт — в адресе', [fakeFetch.last.options.headers['X-Bitrix-Csrf-Token'], fakeFetch.last.options.body.startsWith('sessid=abc'), fakeFetch.last.url.endsWith('&SITE_ID=s1')], ['abc', true, true]);
const denied = await run(fakeFetch('application/json', [], {status: 'error', errors: [{message: 'Нет доступа', code: 'access'}]}));
same('отказ до потока — ошибка ajax как есть', denied, ['error:Нет доступа']);
const broken = await run(fakeFetch('text/event-stream', ['data: {"type":"delta","text":"по"}\n\n']));
same('поток кончился без finish — «оборвался»', broken, ['delta:по', 'error:Ответ модели оборвался — спросите ещё раз']);
const failed = await run(fakeFetch('text/event-stream', ['data: {"type":"error","code":"model","message":"Модель не ответила"}\n\n']));
same('событие error — текст сервера', failed, ['error:Модель не ответила']);

if (errors.length) {
  console.log(`Провалено ${errors.length} из ${count}`);
  process.exit(1);
}
console.log(`Проверок пройдено: ${count}`);
