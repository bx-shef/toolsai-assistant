/**
 * Виджеты в ответах (install/js/.../chat/widgets.js, 1.7.0): встроенные типы,
 * свой тип модуля, безопасность — всё от модели только текстом, ссылка — только
 * на карточку записи своего портала. DOM — подставной (без браузера).
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const W = require(path.join(root, 'install/js/shef/toolsaiassistant/chat/widgets.js'));

let count = 0;
const errors = [];
function same(what, actual, expected) {
  count++;
  const a = JSON.stringify(actual), e = JSON.stringify(expected);
  if (a === e) { console.log(`  OK   ${what}`); } else { errors.push(what); console.log(`  FAIL ${what}: получено ${a}, ожидалось ${e}`); }
}

/** Подставной узел: дети, атрибуты, textContent; innerHTML — запрещён (ловушка). */
function node(tag, cls, text) {
  const n = {
    nodeType: 1, tag, children: [], attrs: {}, listeners: {}, style: {},
    className: cls || '', textContent: text === undefined ? '' : String(text),
    classList: { add(c) { n.className += ' ' + c; } },
    appendChild(c) { n.children.push(c); return c; },
    setAttribute(k, v) { n.attrs[k] = String(v); },
    addEventListener(t, f) { n.listeners[t] = f; },
    set innerHTML(v) { throw new Error('innerHTML запрещён'); },
  };
  return n;
}
const calls = [];
const ctx = { el: node, prefill: t => calls.push(['prefill', t]), send: t => calls.push(['send', t]), recordUrl: W.recordUrl, safeUrl: () => null };
const mount = (type, data) => W.mount(node('div', 'shef-aia-widget'), { type, data: { type, ...data } }, ctx);
const texts = n => [n.textContent, ...n.children.flatMap(texts)].filter(Boolean);
const find = (n, tag) => [...(n.tag === tag ? [n] : []), ...n.children.flatMap(c => find(c, tag))];

console.log('\nвстроенные');
same('типы', W.builtins(), ['kpi', 'table', 'bars', 'record', 'actions']);
const kpi = mount('kpi', { title: 'Итог', items: [{ label: 'Сделок', value: 12, trend: 'up' }, { label: '<img src=x onerror=1>', value: '5' }, 'мусор'] });
same('kpi: заголовок, значения и подписи текстом, мусор пропущен', texts(kpi), ['Итог', '12', ' ▲', 'Сделок', '5', '<img src=x onerror=1>']);
const table = mount('table', { columns: ['Сделка', 'Сумма'], rows: [['А', 100, 'лишняя'], ['Б', { x: 1 }]] });
same('table: строки по числу столбцов, объект — пусто', find(table, 'td').map(t => t.textContent), ['А', '100', 'Б', '']);
same('table: не больше 50 строк', find(mount('table', { columns: ['a'], rows: Array.from({ length: 80 }, (_, i) => [i]) }), 'tr').length, 51);
const bars = mount('bars', { unit: 'шт', items: [{ label: 'Новые', value: 10 }, { label: 'В работе', value: '5' }, { label: 'Минус', value: -1 }] });
same('bars: ширина от максимума, отрицательное пропущено', find(bars, 'span').filter(s => s.className === 'shef-aiw-bar-fill').map(s => s.style.width), ['100%', '50%']);
const rec = mount('record', { entityTypeId: 2, id: 42, title: 'Окна', stage: 'Новая', fields: [{ label: 'Сумма', value: '1500 BYN' }] });
same('record: ссылка на карточку сделки своего портала', [find(rec, 'a')[0].attrs.href, find(rec, 'a')[0].attrs['data-shef-link']], ['/crm/deal/details/42/', '1']);
same('record: название — один раз (ссылкой)', texts(rec).filter(t => t === 'Окна').length, 1);
same('record: смарт-процесс', W.recordUrl(1032, 7), '/crm/type/1032/details/7/');
same('record: мусорный ID — без ссылки', [W.recordUrl(2, '1;alert'), W.recordUrl(-1, 2), W.recordUrl(2, 1.5), find(mount('record', { entityTypeId: 'x', id: 1, title: 'T' }), 'a').length], ['', '', '', 0]);
const actions = mount('actions', { items: [{ label: 'Позвонить', prompt: 'Подготовь к звонку' }, { label: 'Сразу', prompt: 'Итог', send: true }, { label: 'Пусто' }] });
const buttons = find(actions, 'button');
buttons.forEach(b => b.listeners.click());
same('actions: пустой запрос — без кнопки; send — отправка, иначе в поле', [buttons.length, calls], [2, [['prefill', 'Подготовь к звонку'], ['send', 'Итог']]]);

console.log('\nмодули и сбои');
same('свой тип модуля регистрируется', W.register('my.mod:weather', { render: (d, c) => c.el('div', '', d.city) }), true);
same('и рисуется', texts(mount('my.mod:weather', { city: 'Минск' })), ['Минск']);
same('негодное имя и без render — отказ', [W.register('Bad Type', { render() {} }), W.register('ok', {})], [false, false]);
same('неизвестный тип — подпись, не падение', texts(mount('nope', {})), ['Виджет недоступен: nope']);
W.register('my.mod:boom', { render() { throw new Error('x'); } });
same('виджет упал — подпись', texts(mount('my.mod:boom', {})), ['Виджет недоступен: my.mod:boom']);
W.register('my.mod:html', { render() { return '<b>строка</b>'; } });
same('вернул строку вместо узла — не вставляется', texts(mount('my.mod:html', {})), ['Виджет недоступен: my.mod:html']);

console.log('');
if (errors.length) { console.log(`Провалено ${errors.length} из ${count}`); process.exit(1); }
console.log(`Проверок пройдено: ${count}`);
