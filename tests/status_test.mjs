/**
 * Этапы ожидания ответа (install/js/.../chat/status.js, 1.3.2): история
 * записи → база знаний → «Думаю…» по start → печать по первому куску.
 * Гоняет build.sh: node tests/status_test.mjs.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const ns = {};
vm.runInNewContext(readFileSync(path.join(root, 'install/js/shef/toolsaiassistant/chat/status.js'), 'utf8'), { BX: { namespace: () => ns } });
const S = ns.ChatStatus;

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
const run = (state, events) => events.reduce((s, e) => S.next(s, e), state);

console.log('\nэтапы');
same('запись и база', S.plan({ record: true, kb: true }), ['record', 'kb', 'model']);
same('общий чат без базы — сразу модель', S.plan({}), ['model']);

console.log('\nчат по записи с базой');
let s = S.initial({ record: true, kb: true });
same('сначала — история записи', S.label(s), 'Читаю историю записи…');
s = S.next(s, 'tick');
same('потом — база знаний', S.label(s), 'Ищу в базе знаний…');
s = run(s, ['tick', 'tick']);
same('таймер не доходит до «Думаю…»', S.stage(s), 'kb');
s = S.next(s, 'start');
same('start — «Думаю…»', S.label(s), 'Думаю…');
s = S.next(s, 'tick');
same('таймер после start ничего не меняет', S.stage(s), 'model');
s = S.next(s, 'delta');
same('первый кусок — индикатор гаснет', [s.phase, S.label(s)], ['typing', '']);
same('следующие куски — печать', S.next(s, 'delta').phase, 'typing');
same('finish — готово', S.next(s, 'finish').phase, 'done');

console.log('\nкраевые');
same('start сразу, без таймера', S.label(S.next(S.initial({ record: true }), 'start')), 'Думаю…');
same('общий чат — «Думаю…» сразу', S.label(S.initial({})), 'Думаю…');
same('отказ до потока — ошибка, без индикатора', S.label(S.next(S.initial({ kb: true }), 'error')), '');
same('delta без start — печать', S.next(S.initial({ kb: true }), 'delta').phase, 'typing');
same('исходное состояние не меняется', (() => { const a = S.initial({ record: true, kb: true }); S.next(a, 'tick'); return a.index; })(), 0);

console.log(`\n${count - errors.length} из ${count}`);
if (errors.length) { process.exit(1); }
