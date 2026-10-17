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
const handlers = {};
const BX = {
  namespace: () => ns,
  ready: (fn) => fn(),
  addCustomEvent: (name, fn) => { (handlers[name] = handlers[name] || []).push(fn); },
};
const frameWindow = {};
vm.runInNewContext(readFileSync(path.join(root, 'install/js/shef/toolsaiassistant/chat/chat.js'), 'utf8'), { BX, window: frameWindow, document: { getElementById: () => null } });
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

console.log('\nкарточка CRM по адресу (1.4.0)');
same('сделка', chat.recordFromPath('/crm/deal/details/15/'), { entityTypeId: 2, id: 15 });
same('лид', chat.recordFromPath('/crm/lead/details/7/?IFRAME=Y'), { entityTypeId: 1, id: 7 });
same('контакт', chat.recordFromPath('/crm/contact/details/3/'), { entityTypeId: 3, id: 3 });
same('компания', chat.recordFromPath('/crm/company/details/4/'), { entityTypeId: 4, id: 4 });
same('смарт-процесс', chat.recordFromPath('/crm/type/128/details/9/'), { entityTypeId: 128, id: 9 });
same('новая запись — нет', chat.recordFromPath('/crm/deal/details/0/'), null);
same('список — нет', chat.recordFromPath('/crm/deal/list/'), null);
same('чужой код — нет', chat.recordFromPath('/crm/foo/details/5/'), null);

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

console.log('\nстраница в слайдере (Frame)');
// Верхнее окно: BX.load запоминает вызовы, «загрузка» заводит настоящий чат
const calls = [];
const loads = [];
const topWindow = {
  BX: {
    load: (files, done) => { loads.push({ files, done }); },
    Shef: { ToolsAiAssistant: { Chat: { init: (settings) => {
      topWindow.inited = settings;
      let open = false;
      topWindow.ShefAiChat = {
        open: (rec) => { open = true; calls.push(['open', rec]); },
        close: () => { open = false; calls.push(['close']); },
        toggle: (rec) => { open = !open; calls.push(['toggle', rec]); },
        isOpen: () => open,
      };
    } } } },
  },
};
const settings = { button: 'b', assets: ['/bitrix/js/shef/toolsaiassistant/chat/chat.js?1'] };
chat.Frame.init(settings, topWindow);
same('без вызова ничего не грузится (лениво)', loads.length, 0);
same('в верхнем окне — заглушка', topWindow.ShefAiChat.stub, true);
same('посредник фрейма: open, close, toggle, isOpen, openCurrent', Object.keys(frameWindow.ShefAiChat), ['open', 'close', 'toggle', 'isOpen', 'openCurrent']);
same('isOpen до загрузки — нет', frameWindow.ShefAiChat.isOpen(), false);
frameWindow.ShefAiChat.toggle(null);
topWindow.ShefAiChat.open({ context: 'docs' });          // справка без BX — через заглушку
handlers['ShefAiChat:open'][0]({ context: 'docs', role: 'r' }); // событие BX
same('три вызова — одна загрузка', loads.length, 1);
loads[0].done();
same('запуск в верхнем окне — без автооткрытия (fromFrame)', topWindow.inited.fromFrame, true);
same('вызовы из очереди дошли по порядку', calls.map((c) => c[0]), ['toggle', 'open', 'open']);
same('после загрузки — настоящий чат, не заглушка', [!!topWindow.ShefAiChat.stub, frameWindow.ShefAiChat.isOpen()], [false, true]);
frameWindow.ShefAiChat.close();
same('дальше — сразу, без загрузки', [loads.length, calls[calls.length - 1][0], frameWindow.ShefAiChat.isOpen()], [1, 'close', false]);

console.log(`\n${count - errors.length} из ${count}`);
if (errors.length) {
  process.exit(1);
}
