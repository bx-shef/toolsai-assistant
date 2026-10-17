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
const ctx = { el: node, prefill: t => calls.push(['prefill', t]), send: t => calls.push(['send', t]), recordUrl: W.recordUrl, safeUrl: u => (/^\/(?![\/\\])/.test(u) ? u : null), call: (n, b) => calls.push(['call', n, b]), userId: 5, greet: (u, t, c) => { calls.push(['greet', u, t, c]); return greetReply(u, t, c); } };
let greetReply = () => Promise.resolve({ name: 'Анна Сидорова', position: 'Бухгалтер' });
const tick = () => new Promise(r => setTimeout(r, 0));
const mount = (type, data) => W.mount(node('div', 'shef-aia-widget'), { type, data: { type, ...data } }, ctx);
const texts = n => [n.textContent, ...n.children.flatMap(texts)].filter(Boolean);
const find = (n, tag) => [...(n.tag === tag ? [n] : []), ...n.children.flatMap(c => find(c, tag))];

console.log('\nвстроенные');
same('типы', W.builtins(), ['kpi', 'table', 'bars', 'record', 'funnel', 'score', 'missedcall', 'tasks', 'task', 'today', 'support', 'birthday', 'doc', 'schedule', 'booking', 'actions']);
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

console.log('\n1.11.0: ссылки в таблице, воронка, оценка, пропущенный звонок');
const linked = mount('table', { columns: ['Клиент', 'Сделка'], rows: [[{ text: 'ООО «А»', entityTypeId: 4, id: 9 }, { text: 'Отчёт', url: '/crm/deal/list/' }], [{ text: 'Чужой', url: 'https://evil.example/' }, { text: 'Протокол', url: '//evil.example/' }], [{ text: 'Плохой ID', entityTypeId: 2, id: '1;x' }, 'просто']] });
same('table: запись CRM и путь портала — ссылки слайдера', find(linked, 'a').map(a => [a.textContent, a.attrs.href, a.attrs['data-shef-link']]), [['ООО «А»', '/crm/company/details/9/', '1'], ['Отчёт', '/crm/deal/list/', '1']]);
same('table: чужой сайт, //хост и мусорный ID — текст без ссылки', find(linked, 'td').map(t => t.children.length ? '[a]' : t.textContent), ['[a]', '[a]', 'Чужой', 'Протокол', 'Плохой ID', 'просто']);
const fun = mount('funnel', { unit: 'шт', items: [{ label: 'Лиды', value: 200 }, { label: 'КП', value: '80 шт' }, { label: 'Договор', value: 20 }, 'мусор'] });
const cls = (n, c) => [...(String(n.className).split(' ').includes(c) ? [n] : []), ...n.children.flatMap(x => cls(x, c))];
same('funnel: ширина 40–100% от наибольшей, «80 шт» — число, не объект пропущен', cls(fun, 'shef-aiw-funnel-bar').map(b => b.style.width), ['100%', '64%', '46%']);
const holes = mount('funnel', { items: [{ label: 'Лиды', value: 200 }, { label: 'КП', value: 'нет данных' }, { label: 'Договор', value: 20 }] });
same('funnel: стадия без числа — на месте с «—», конверсия через неё не выдумывается', [cls(holes, 'shef-aiw-funnel-value').map(v => v.textContent), cls(holes, 'shef-aiw-funnel-step').map(v => v.textContent), cls(holes, 'shef-aiw-funnel-sum')[0].textContent], [['200', '—', '20'], ['—', '—'], 'Итоговая конверсия: 10%']);
same('funnel: конверсия из предыдущей и от начала, итог', [cls(fun, 'shef-aiw-funnel-step').map(s => s.textContent), cls(fun, 'shef-aiw-funnel-total').map(s => s.textContent), cls(fun, 'shef-aiw-funnel-sum')[0].textContent], [['↓ 40%', '↓ 25%'], [' · от начала 10%'], 'Итоговая конверсия: 10%']);
same('funnel: предыдущая стадия 0 — прочерк', cls(mount('funnel', { items: [{ label: 'a', value: 0 }, { label: 'b', value: 0 }] }), 'shef-aiw-funnel-step').map(s => s.textContent), ['—']);
const sc = mount('score', { value: 65, label: 'По скрипту', summary: 'Не выявил потребность', criteria: [{ label: 'Поздоровался', passed: true }, { label: 'Потребность', passed: 'yes', note: 'нет вопросов' }, 'мусор'] });
same('score: зона по доле, проценты при max 100', [cls(sc, 'shef-aiw-score-value')[0].className, cls(sc, 'shef-aiw-score-value')[0].textContent, cls(sc, 'shef-aiw-score-fill')[0].style.width], ['shef-aiw-score-value --mid', '65%', '65%']);
same('score: ✓ только при passed === true, мусор пропущен', find(sc, 'li').map(l => l.className), ['--ok', '--fail']);
same('score: свой максимум и зоны', [cls(mount('score', { value: 9, max: 10 }), 'shef-aiw-score-value')[0].textContent, cls(mount('score', { value: 30 }), 'shef-aiw-score-fill')[0].className, cls(mount('score', { value: 500 }), 'shef-aiw-score-fill')[0].style.width], ['9 / 10', 'shef-aiw-score-fill --bad', '100%']);
calls.length = 0;
const mc = mount('missedcall', { phone: '+375 (29) 123-45-67', name: 'Иван', time: '10:42', waiting: '2 ч', entityTypeId: 2, id: 118 });
find(mc, 'button').forEach(b => b.listeners.click());
same('missedcall: перезвонить по номеру с привязкой, карточка, подготовка', [find(mc, 'button').map(b => b.textContent), find(mc, 'a')[0].attrs.href, calls], [['Перезвонить', 'Подготовиться к звонку'], '/crm/deal/details/118/', [['call', '+375291234567', { entityTypeId: 2, id: 118 }], ['prefill', 'Подготовь меня к звонку: Иван, +375291234567']]]);
same('missedcall: негодный номер — без «Перезвонить», без записи — без карточки', [find(mount('missedcall', { phone: '12; alert(1)' }), 'button').map(b => b.textContent), find(mount('missedcall', { phone: '12; alert(1)' }), 'a').length], [['Подготовиться к звонку'], 0]);

console.log('\n1.11.0: задачи, сегодня, саппорт, день рождения, документация, расписание, запись');
const hrefs = n => find(n, 'a').map(a => a.attrs.href);
const tl = mount('tasks', { items: [{ id: 7, title: 'КП Альфе', deadline: '15.10', overdue: true, responsible: 'Петров', urgent: true }, { id: 'x', title: 'Без ID' }, 'мусор'] });
same('tasks: ссылка на задачу текущего сотрудника, мусорный ID — без ссылки', hrefs(tl), ['/company/personal/user/5/tasks/task/view/7/']);
same('tasks: горит — 🔥, просрочка — красным', [texts(tl).includes('🔥'), cls(tl, 'shef-aiw-due')[0].className, cls(tl, 'shef-aiw-due')[0].textContent], [true, 'shef-aiw-due --overdue', 'просрочена · 15.10']);
const tc = mount('task', { id: 9, title: 'Договор', status: 'В работе', entityTypeId: 2, entityId: 118, entityTitle: 'Поставка', checklist: [{ label: 'Счёт', done: true }, { label: 'Акт' }] });
same('task: задача, привязка CRM, чек-лист', [hrefs(tc), cls(tc, 'shef-aiw-check-sum')[0].textContent, find(tc, 'li').map(l => l.className)], [['/company/personal/user/5/tasks/task/view/9/', '/crm/deal/details/118/'], 'Чек-лист: 1 из 2', ['--done', '']]);
const td = mount('today', { items: [{ kind: 'call', title: 'Позвонить Альфе', time: '11:00', entityTypeId: 2, id: 1 }, { kind: 'task', title: 'Счёт', urgent: true, taskId: 3 }, { kind: 'meeting', title: 'Планёрка', url: '//evil' }] });
same('today: горит — первым, разделитель, ссылки по типу', [cls(td, 'shef-aiw-today-head').map(h => h.textContent), cls(td, 'shef-aiw-task-title').map(t => t.textContent), hrefs(td)], [['Горит: 1', 'Остальное'], ['Счёт', 'Позвонить Альфе', 'Планёрка'], ['/company/personal/user/5/tasks/task/view/3/', '/crm/deal/details/1/']]);
const sp = mount('support', { items: [{ title: 'А', sla: 'ok' }, { title: 'Б', sla: 'breach', entityTypeId: 1, id: 4 }, { title: 'В', sla: 'risk' }, { title: 'Г', sla: 'meh' }] });
same('support: нарушен срок — сверху, счётчики', [cls(sp, 'shef-aiw-task-title').map(t => t.textContent), cls(sp, 'shef-aiw-support-head')[0].textContent, hrefs(sp)], [['Б', 'В', 'А', 'Г'], 'Обращений: 4 · нарушен срок: 1 · на грани: 1', ['/crm/lead/details/4/']]);
calls.length = 0;
const bd = mount('birthday', { userId: 12, name: 'Анна', position: 'Бухгалтер', message: 'С днём рождения!\n\nКоллектив отдела' });
const [sendBtn, genBtn] = find(bd, 'button');
const area = find(bd, 'textarea')[0];
same('birthday: переносы строк в тексте модели сохраняются', area.value, 'С днём рождения!\n\nКоллектив отдела');
sendBtn.listeners.click();
await tick();
same('birthday: первое нажатие — проверка получателя (confirm=false), подтверждение с именем с портала', [calls, sendBtn.textContent, cls(bd, 'shef-aiw-bday-status')[0].textContent], [[['greet', 12, 'С днём рождения!\n\nКоллектив отдела', false]], 'Точно отправить: Анна Сидорова?', 'Получатель: Анна Сидорова, Бухгалтер. Сообщение уйдёт в личный чат от вашего имени.']);
sendBtn.listeners.click();
await tick();
same('birthday: второе — отправка (confirm=true), всё заблокировано, профиль сотрудника', [calls.map(c => c[3]), sendBtn.textContent, sendBtn.disabled, area.disabled, genBtn.disabled, hrefs(bd)], [[false, true], 'Отправлено ✓', true, true, true, ['/company/personal/user/12/']]);
const again = mount('birthday', { userId: 12, name: 'Анна', message: 'С днём рождения!\n\nКоллектив отдела' });
same('birthday: перерисовка ответа не даёт отправить ещё раз', [find(again, 'button')[0].textContent, find(again, 'button')[0].disabled], ['Отправлено ✓', true]);
calls.length = 0;
const bd3 = mount('birthday', { userId: 12, name: 'Анна', message: 'Поздравляю' });
const [send3] = find(bd3, 'button');
send3.listeners.click();
await tick();
find(bd3, 'textarea')[0].value = 'Поздравляю! Подпись';
find(bd3, 'textarea')[0].listeners.input();
send3.listeners.click();
await tick();
same('birthday: правка текста после проверки — снова проверка, не отправка; черновик переживает перерисовку', [calls.map(c => [c[2], c[3]]), find(mount('birthday', { userId: 12, name: 'Анна', message: 'Поздравляю' }), 'textarea')[0].value], [[['Поздравляю', false], ['Поздравляю! Подпись', false]], 'Поздравляю! Подпись']);
calls.length = 0;
greetReply = () => Promise.reject('У Пётр Иванов сейчас не день рождения — поздравление не отправлено');
const bd4 = mount('birthday', { userId: 13, name: 'Анна', message: 'Привет' });
find(bd4, 'button')[0].listeners.click();
await tick();
same('birthday: отказ сервера — текст отказа, кнопка снова доступна', [cls(bd4, 'shef-aiw-bday-status')[0].textContent, find(bd4, 'button')[0].textContent, find(bd4, 'button')[0].disabled], ['У Пётр Иванов сейчас не день рождения — поздравление не отправлено', 'Отправить в чат', false]);
greetReply = () => Promise.resolve({ name: 'Анна Сидорова', position: 'Бухгалтер' });
calls.length = 0;
genBtn.listeners.click();
same('birthday: «Сгенерировать» — запрос модели', [calls.length, calls[0][0], calls[0][1].includes('userId 12')], [1, 'send', true]);
calls.length = 0;
const bd2 = mount('birthday', { userId: 12, name: 'Анна' });
find(bd2, 'button')[0].listeners.click();
same('birthday: пустой текст — не отправляется', [calls, cls(bd2, 'shef-aiw-bday-status')[0].textContent], [[], 'Напишите поздравление или нажмите «Сгенерировать»']);
const dc = mount('doc', { items: [{ title: 'Права', url: '/shef/toolsai/kb/rights/', summary: 'Кто что видит', tags: ['права'] }, { title: 'Чужая', url: 'javascript:alert(1)' }] });
same('doc: путь портала — ссылка, javascript: — нет', [hrefs(dc), cls(dc, 'shef-aiw-doc-summary')[0].textContent], [['/shef/toolsai/kb/rights/'], 'Кто что видит']);
same('расписание: пересечения — колонками, освободившаяся колонка занимается снова', W.layoutDay([{ from: 540, to: 600 }, { from: 570, to: 630 }, { from: 660, to: 690 }, { from: 545, to: 560 }]), [{ col: 0, cols: 2 }, { col: 1, cols: 2 }, { col: 0, cols: 1 }, { col: 1, cols: 2 }]);
const sch = mount('schedule', { now: '10:30', events: [{ title: 'Планёрка', from: '09:00', to: '10:00', eventId: 44 }, { title: 'Иванов', from: '09:30', to: '10:30', kind: 'booking', bookingId: 8 }, { title: 'Отпуск Пети', allDay: true }, { title: 'Мусор', from: '25:99' }] });
const evs = cls(sch, 'shef-aiw-sched-event');
same('schedule: от 08:00, по 44px в час, колонки, «сейчас»', [evs.map(e => [e.style.top, e.style.height, e.style.left, e.style.width]), cls(sch, 'shef-aiw-sched-now')[0].style.top, cls(sch, 'shef-aiw-sched-chip').length], [[['44px', '42px', '0%', '50%'], ['66px', '42px', '50%', '50%']], '110px', 1]);
same('schedule: событие календаря и запись — ссылками', hrefs(sch), ['/company/personal/user/5/calendar/?EVENT_ID=44', '/booking/detail/8/']);
calls.length = 0;
const bk = mount('booking', { date: '17.10', waitlist: 2, items: [{ id: 31, from: '14:00', to: '15:00', client: 'Сидоров', resource: 'Кабинет 2', confirmed: false, phone: '+375291112233', entityTypeId: 3, entityId: 5 }, { id: 30, from: '09:00', to: '09:30', client: 'Анна', visit: 'visited', confirmed: true }] });
find(bk, 'button')[0].listeners.click();
same('booking: по времени, слайдер записи, CRM, журнал', [cls(bk, 'shef-aiw-task-title').map(t => t.textContent), hrefs(bk)], [['Анна', 'Сидоров'], ['/booking/detail/30/', '/booking/detail/31/', '/crm/contact/details/5/', '/booking/']]);
same('booking: шапка, отметки, звонок клиенту', [cls(bk, 'shef-aiw-support-head')[0].textContent, cls(bk, 'shef-aiw-badge').map(b => b.textContent), calls], ['17.10 · записей: 2 · не подтверждено: 1', ['подтверждена', 'пришёл', 'не подтверждена'], [['call', '+375291112233', { entityTypeId: 3, id: 5 }]]]);

console.log('\nкрая данных модели (ревью PR #20)');
same('score: зоны на границах 49/50/79/80%, свой максимум', [49, 50, 79, 80].map(v => cls(mount('score', { value: v }), 'shef-aiw-score-fill')[0].className).concat([cls(mount('score', { value: 4, max: 5 }), 'shef-aiw-score-fill')[0].className]), ['shef-aiw-score-fill --bad', 'shef-aiw-score-fill --mid', 'shef-aiw-score-fill --mid', 'shef-aiw-score-fill --good', 'shef-aiw-score-fill --good']);
same('score: «85%» — тоже число', cls(mount('score', { value: '85%' }), 'shef-aiw-score-fill')[0].style.width, '85%');
const loose = { ...ctx, safeUrl: u => u };
const looseTable = W.mount(node('div', ''), { type: 'table', data: { columns: ['a'], rows: [[{ text: 'x', url: '//evil.example/' }], [{ text: 'y', url: '/\\evil' }]] } }, loose);
same('table: //хост и /\\хост — без ссылки, даже если safeUrl пропустит', find(looseTable, 'a').length, 0);
same('phone: 21 цифра — без «Перезвонить»', find(mount('missedcall', { phone: '1'.repeat(21) }), 'button').map(b => b.textContent), ['Подготовиться к звонку']);
same('расписание: одно начало — длинное событие левее', W.layoutDay([{ from: 540, to: 570 }, { from: 540, to: 660 }]), [{ col: 1, cols: 2 }, { col: 0, cols: 2 }]);
same('ключи прототипа из данных модели — как неизвестные', [cls(mount('support', { items: [{ title: 'А', sla: 'toString' }] }), 'shef-aiw-sla')[0].textContent, cls(mount('today', { items: [{ title: 'А', kind: 'constructor' }] }), 'shef-aiw-kind').length, cls(mount('booking', { items: [{ client: 'А', visit: 'constructor' }] }), 'shef-aiw-badge').length, cls(mount('schedule', { events: [{ title: 'А', from: '09:00', kind: '__proto__' }] }), 'shef-aiw-sched-event')[0].className], ['—', 0, 0, 'shef-aiw-sched-event --other']);
same('today: негодный taskId — ссылка на запись CRM', hrefs(mount('today', { items: [{ title: 'Сделка', taskId: '—', entityTypeId: 2, id: 118 }] })), ['/crm/deal/details/118/']);
same('support: неизвестный sla — в конце, «—»', cls(mount('support', { items: [{ title: 'Х', sla: 'meh' }, { title: 'Б', sla: 'breach' }] }), 'shef-aiw-sla').map(x => x.className), ['shef-aiw-sla --breach', 'shef-aiw-sla --none']);
same('doc: без url — текст без ссылки', [hrefs(mount('doc', { items: [{ title: 'Без адреса' }] })).length, texts(mount('doc', { items: [{ title: 'Без адреса' }] })).includes('📄 Без адреса')], [0, true]);
same('schedule: пустой день — «Встреч нет»', texts(mount('schedule', { events: [] })).includes('Встреч нет'), true);
same('booking: без телефона и с негодным — без кнопки звонка', find(mount('booking', { items: [{ id: 1, client: 'А' }, { id: 2, client: 'Б', phone: 'abc' }] }), 'button').length, 0);
same('task: без id — без ссылки, текст «Задача»', [hrefs(mount('task', { title: '' })).length, texts(mount('task', {})).includes('Задача')], [0, true]);

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
