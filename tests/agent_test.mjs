/**
 * Режим «Агент» в окне (install/js/.../chat/agent.js, 1.10.0): подписи шагов,
 * переписка для сервера без объявленных действий, ответ сервера → сообщение
 * (только нужные поля), карточка «Выполнить / Отмена», сообщение окна
 * подключения — только своё. DOM — подставной: всё текстом, innerHTML запрещён.
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const A = require(path.join(root, 'install/js/shef/toolsaiassistant/chat/agent.js'));

let count = 0;
const errors = [];
function same(what, actual, expected) {
  count++;
  const a = JSON.stringify(actual), e = JSON.stringify(expected);
  if (a === e) { console.log(`  OK   ${what}`); } else { errors.push(what); console.log(`  FAIL ${what}: получено ${a}, ожидалось ${e}`); }
}
function node(tag, cls, text) {
  const n = {
    nodeType: 1, tag, children: [], attrs: {}, listeners: {}, className: cls || '', textContent: text === undefined ? '' : String(text),
    appendChild(c) { n.children.push(c); return c; },
    setAttribute(k, v) { n.attrs[k] = String(v); },
    addEventListener(t, f) { n.listeners[t] = f; },
    set innerHTML(v) { throw new Error('innerHTML запрещён'); },
  };
  return n;
}
const texts = n => [n.textContent, ...n.children.flatMap(texts)].filter(Boolean);
const find = (n, tag) => [...(n.tag === tag ? [n] : []), ...n.children.flatMap(c => find(c, tag))];

console.log('\nшаги');
same('подписи', [A.stepLabel({ title: 'Найти в CRM', status: 'done' }), A.stepLabel({ tool: 'x', status: 'denied', note: 'Только РОП' }), A.stepLabel({ title: 'Т', status: 'declined' })],
  ['✓ Найти в CRM — выполнено', '⛔ x — запрещено на портале: Только РОП', '✕ Т — отменено']);
const steps = A.renderSteps([{ title: '<b>x</b>', status: 'error"><img', readOnly: true }], node);
same('шаг — текстом, неизвестный статус — «•», класс без мусора', [texts(steps), steps.children[0].className], [['• <b>x</b>'], 'shef-aia-agent-step --errorimg']);

console.log('\nпереписка');
same('объявленное действие и пустые — не уходят серверу, файлы — уходят', A.history([
  { role: 'user', text: 'Поставь задачу', files: [{ name: 'a.pdf' }] },
  { role: 'assistant', text: 'Сейчас поставлю', pending: { id: 'a' } },
  { role: 'assistant', text: '' },
  { role: 'assistant', text: 'Готово', steps: [] },
]), [{ role: 'user', text: 'Поставь задачу', files: [{ name: 'a.pdf' }] }, { role: 'assistant', text: 'Готово' }]);
const m = A.message({ text: 'Смотрю', role: 'r', steps: [{ tool: 't', title: 'Т', status: 'done', readOnly: 1, extra: 'x' }], pending: { id: 'abc', tool: 'task_create', title: 'Поставить задачу', readOnly: false, preview: '{"title":"Б"}', arguments: { secret: 1 } } });
same('ответ сервера → сообщение: только нужные поля', [m.text, m.agent, m.steps, m.pending], ['Смотрю', true, [{ tool: 't', title: 'Т', status: 'done', readOnly: true, note: '' }], { id: 'abc', tool: 'task_create', title: 'Поставить задачу', readOnly: false, preview: '{"title":"Б"}' }]);
same('без ждущего — нет pending; id не строка — нет', [A.message({ text: 'x' }).pending, A.message({ text: 'x', pending: { id: 5 } }).pending], [undefined, undefined]);

console.log('\nкарточка подтверждения');
const decided = [];
const card = A.renderPending(m.pending, '', node, d => decided.push(d));
const buttons = find(card, 'button');
buttons.forEach(b => b.listeners.click());
same('что выполнит и с какими параметрами; кнопки решают', [texts(card).slice(0, 2), buttons.map(b => b.textContent), decided], [['Агент хочет выполнить: Поставить задачу', '{"title":"Б"}'], ['Выполнить', 'Отмена'], ['yes', 'no']]);
same('чтение с подтверждением — «посмотреть»; без параметров — без строки', texts(A.renderPending({ title: 'Найти', readOnly: true, preview: '{}' }, '', node, () => {})), ['Агент хочет посмотреть: Найти', 'Выполнить', 'Отмена']);
same('решено — кнопок нет', [find(A.renderPending(m.pending, 'yes', node, () => {}), 'button').length, texts(A.renderPending(m.pending, 'no', node, () => {})).pop()], [0, 'Отменено']);

console.log('\nподключение');
same('сообщение окна возврата — только своё', [
  A.isConnectMessage({ origin: 'https://p.by', data: { type: A.MESSAGE_TYPE, ok: true } }, 'https://p.by'),
  A.isConnectMessage({ origin: 'https://evil.by', data: { type: A.MESSAGE_TYPE, ok: true } }, 'https://p.by'),
  A.isConnectMessage({ origin: 'https://p.by', data: 'x' }, 'https://p.by'),
], [true, false, false]);

console.log(`\nПроверок пройдено: ${count - errors.length}`);
if (errors.length) { console.log(`Провалено ${errors.length} из ${count}`); process.exit(1); }
