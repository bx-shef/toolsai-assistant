/**
 * Меню промптов в поле ввода (install/js/.../chat/promptmenu.js, 1.7.0):
 * группы как у задач, поиск, что делать по выбору, когда открывать, вид окна.
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const M = require(path.join(root, 'install/js/shef/toolsaiassistant/chat/promptmenu.js'));

let count = 0;
const errors = [];
function same(what, actual, expected) {
  count++;
  const a = JSON.stringify(actual), e = JSON.stringify(expected);
  if (a === e) { console.log(`  OK   ${what}`); } else { errors.push(what); console.log(`  FAIL ${what}: получено ${a}, ожидалось ${e}`); }
}

const p = (code, group, extra = {}) => ({ code, title: code, text: 'текст ' + code, type: 'plain', group, favorite: false, ...extra });
const list = [p('общий', 'chat'), p('карточка', 'card'), p('избранный', 'card', { favorite: true }), p('список', 'list'), p('чужая группа', 'zzz'), { title: 1 }];
const groups = M.groups(list, '');
same('порядок: избранные, карточка, список, общие (неизвестная группа — в общие)', groups.map(g => [g.title, g.items.map(i => i.code)]), [['Избранные', ['избранный']], ['Для этой записи', ['карточка']], ['Для списка', ['список']], ['Общие', ['общий', 'чужая группа']]]);
same('поиск по названию и тексту, без регистра и «ё»', M.groups([p('Риски Сделки', 'card'), p('x', 'chat', { text: 'Ещё звонок' })], 'еще').map(g => g.items[0].code), ['x']);
same('пусто — нет групп', M.groups(null, ''), []);
same('с уточнениями — в поле, иначе отправка', [M.action({ type: 'template' }), M.action({ type: 'plain' }), M.action(null)], ['prefill', 'send', 'send']);
same('«/» в пустом поле открывает, в тексте — нет', [M.opensOn('', '/'), M.opensOn('  ', '/'), M.opensOn('a', '/'), M.opensOn('', 'a')], [true, true, false, false]);
same('вид окна: список CRM без записи; с записью и вне CRM — пусто; настройки CRM — не список', [M.view(false, '/crm/deal/category/0/'), M.view(true, '/crm/deal/'), M.view(false, '/company/'), M.view(false, '/crm/configs/')], ['list', '', '', '']);

console.log('');
if (errors.length) { console.log(`Провалено ${errors.length} из ${count}`); process.exit(1); }
console.log(`Проверок пройдено: ${count}`);
