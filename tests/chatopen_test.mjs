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
same('посредник фрейма: open, close, toggle, isOpen, openCurrent, agent (1.9.0)', Object.keys(frameWindow.ShefAiChat), ['open', 'close', 'toggle', 'isOpen', 'openCurrent', 'agent']);
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

console.log('\nверхнее окно: inert слайдера и автооткрытие (1.7.1)');
// Минимальный DOM: любой узел принимает любые вызовы; атрибуты и классы запоминаем
function fakeNode(tag) {
  const attrs = {};
  const classes = new Set();
  const node = {
    tagName: tag, attrs, children: [], style: { setProperty() {} }, dataset: {},
    classList: { add: (c) => classes.add(c), remove: (c) => classes.delete(c), contains: (c) => classes.has(c), toggle: (c, on) => { (on ?? !classes.has(c)) ? classes.add(c) : classes.delete(c); } },
    setAttribute: (k, v) => { attrs[k] = String(v); }, getAttribute: (k) => attrs[k] ?? null, removeAttribute: (k) => { delete attrs[k]; },
    appendChild: (c) => { node.children.push(c); return c; }, addEventListener() {}, removeEventListener() {}, focus() {}, querySelector: () => null, querySelectorAll: () => [],
    getBoundingClientRect: () => ({ left: 0, right: 0, width: 0, top: 0, bottom: 0 }), offsetWidth: 0,
  };
  return new Proxy(node, { get: (t, k) => (k in t ? t[k] : (typeof k === 'string' && /^(insert|replace|remove|scroll|select)/.test(k) ? () => {} : undefined)), set: (t, k, v) => { t[k] = v; return true; } });
}
function runTop(session, local) {
  const ns2 = {};
  const body = fakeNode('body');
  const byId = {};
  const doc = {
    body, createElement: (t) => fakeNode(t), createTextNode: (t) => ({ textContent: t }),
    getElementById: (id) => byId[id] || null, addEventListener() {}, removeEventListener() {}, querySelectorAll: () => [],
  };
  body.appendChild = (c) => { if (c.id) { byId[c.id] = c; } body.children.push(c); return c; };
  const store = (data) => ({ getItem: (k) => (k in data ? data[k] : null), setItem: (k, v) => { data[k] = String(v); }, removeItem: (k) => { delete data[k]; } });
  const win = { innerWidth: 1600, localStorage: store(local), sessionStorage: store(session), addEventListener() {} };
  win.top = win;
  const BX2 = {
    namespace: () => ns2, ready: () => {}, addCustomEvent() {}, cleanNode() {},
    ajax: { runAction: () => ({ then() {} }) },
  };
  vm.runInNewContext(readFileSync(path.join(root, 'install/js/shef/toolsaiassistant/chat/chat.js'), 'utf8'), { BX: BX2, window: win, document: doc, setTimeout: () => 0, Date, JSON, Object, Array, String, Number, Math });
  ns2.Chat.init({ button: 'btn', z: 990, min: 380, max: 900, def: 440 });
  return { panel: byId['shef-aia-chat'], win, session, local };
}
{
  const t = runTop({}, { 'shef-ai-chat-open': '1' });
  same('панель не делается inert слайдером (data-a11y-ignore-inert)', t.panel && t.panel.getAttribute(chat.INERT_OPT_OUT), 'true');
  same('атрибут — штатный ui.a11y FocusTrap', chat.INERT_OPT_OUT, 'data-a11y-ignore-inert');
  same('метка «открыт» 1.7.0 в localStorage — не открывает и стирается', [t.win.ShefAiChat.isOpen(), 'shef-ai-chat-open' in t.local], [false, false]);
  t.win.ShefAiChat.open(null);
  same('открыли — метка в этой вкладке (sessionStorage)', [t.win.ShefAiChat.isOpen(), t.session['shef-ai-chat-open'], 'shef-ai-chat-open' in t.local], [true, '1', false]);
  t.panel.setAttribute('inert', 'true');
  t.win.ShefAiChat.close();
  t.win.ShefAiChat.open(null);
  same('открытие снимает inert, если его уже поставили', t.panel.getAttribute('inert'), null);
  t.win.ShefAiChat.close();
  same('закрыли — метка «0»', t.session['shef-ai-chat-open'], '0');
}
{
  const t = runTop({ 'shef-ai-chat-open': '1' }, {});
  same('эта вкладка открывала чат — после перезагрузки открыт', t.win.ShefAiChat.isOpen(), true);
}

console.log(`\n${count - errors.length} из ${count}`);
if (errors.length) {
  process.exit(1);
}
