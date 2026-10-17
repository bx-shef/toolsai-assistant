/**
 * Что открывает window.ShefAiChat.open() (install/js/.../chat/chat.js, 1.3.0):
 * запись CRM или чат справки {context: 'docs', role, title, question}; ключ
 * переписки — как на сервере, у справки свой («docs»).
 * Гоняет build.sh: node tests/chatopen_test.mjs.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const ns = {};
const BX = { namespace: () => ns };
vm.runInNewContext(readFileSync(path.join(root, 'install/js/shef/toolsaiassistant/chat/chat.js'), 'utf8'), { BX, window: {}, document: {} });
const chat = ns.Chat;

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

console.log('\nзапись CRM');
same('пусто — общий чат', chat.normalizeRecord(null), null);
same('запись — целые', chat.normalizeRecord({ entityTypeId: '2', id: 5, title: 'Сделка' }), { entityTypeId: 2, id: 5, title: 'Сделка' });
same('неположительный id — общий чат', chat.normalizeRecord({ entityTypeId: 2, id: 0 }), null);
same('ключ записи', chat.chatKey({ entityTypeId: 2, id: 5 }), 'record:2:5');
same('ключ общего', chat.chatKey(null), 'general');

console.log('\nсправка');
const docs = chat.normalizeRecord({ context: 'docs', role: 'shef.toolsai:assistant', title: 'Звонки', question: 'Как включить?' });
same('справка — роль, тема, вопрос', docs, { docs: true, title: 'Звонки', role: 'shef.toolsai:assistant', question: 'Как включить?' });
same('ключ справки — свой', chat.chatKey(docs), 'docs');
same('роль с мусором — без роли', chat.normalizeRecord({ context: 'docs', role: '<script>' }).role, '');
same('без темы — «Справка»', chat.normalizeRecord({ context: 'docs' }).title, 'Справка');
same('вопрос — не длиннее 2000', chat.normalizeRecord({ context: 'docs', question: 'x'.repeat(5000) }).question.length, 2000);

console.log(`\n${count - errors.length} из ${count}`);
if (errors.length) {
  process.exit(1);
}
