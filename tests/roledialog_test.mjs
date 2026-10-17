/**
 * Модель диалога «Выбрать роль» (install/js/.../chat/roledialog.js, 1.4.0):
 * категории, поиск, недавние и избранное, выбранная роль.
 * Гоняет build.sh: node tests/roledialog_test.mjs.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const ns = {};
const context = { BX: { namespace: () => ns } };
vm.createContext(context);
for (const file of ['view.js', 'roledialog.js']) {
  vm.runInContext(readFileSync(path.join(root, 'install/js/shef/toolsaiassistant/chat', file), 'utf8'), context);
}
const M = ns.RoleDialogModel;

let count = 0;
const errors = [];
function same(what, actual, expected) {
  count++;
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  if (a === e) { console.log(`  OK   ${what}`); } else { errors.push(what); console.log(`  FAIL ${what}: получено ${a}, ожидалось ${e}`); }
}
const roles = [
  { code: 'a', title: 'Помощник менеджера', description: 'Про свою сделку', icon: 'o-person', group: 'Продажи' },
  { code: 'b', title: 'Руководитель', description: 'Узкие места отдела', icon: 'o-crown', group: 'Управление' },
  { code: 'c', title: 'Ёмкий наставник', description: 'Учит', group: 'Продажи' },
];
const ids = (list) => list.map((x) => x.id);
const codes = (list) => list.map((x) => x.code);

console.log('\nкатегории');
same('без недавних: избранное, все, группы', ids(M.categories(roles, { recent: [], favorite: [] })), ['favorite', 'all', 'group:Продажи', 'group:Управление']);
same('недавние — первыми', ids(M.categories(roles, { recent: ['b'], favorite: [] }))[0], 'recent');
same('закрытая роль в недавних не считается', ids(M.categories(roles, { recent: ['zzz'], favorite: [] }))[0], 'favorite');
same('одна группа — групп не показываем', ids(M.categories(roles.map((r) => ({ ...r, group: 'Х' })), {})), ['favorite', 'all']);
same('счётчики', M.categories(roles, { recent: [], favorite: ['a', 'zzz'] }).map((c) => c.count), [1, 3, 2, 1]);
same('по умолчанию — недавние, если есть', [M.defaultCategory(roles, { recent: ['a'] }), M.defaultCategory(roles, {})], ['recent', 'all']);

console.log('\nроли справа');
same('все', codes(M.items(roles, {}, 'all', '', 'a')), ['a', 'b', 'c']);
same('выбранная отмечена', M.items(roles, {}, 'all', '', 'b').map((x) => x.selected), [false, true, false]);
same('недавние — в порядке выбора', codes(M.items(roles, { recent: ['c', 'a'] }, 'recent', '', '')), ['c', 'a']);
same('избранное — со звёздочкой', M.items(roles, { favorite: ['b'] }, 'favorite', '', '').map((x) => [x.code, x.favorite]), [['b', true]]);
same('группа', codes(M.items(roles, {}, 'group:Продажи', '', '')), ['a', 'c']);
same('иконка по умолчанию', M.items(roles, {}, 'all', '', '')[2].icon, 'o-copilot');

console.log('\nпоиск');
same('по описанию, без регистра', codes(M.items(roles, {}, 'favorite', 'УЗКИЕ', '')), ['b']);
same('«е» находит «ё»', codes(M.items(roles, {}, 'all', 'емкий', '')), ['c']);
same('ничего — текст', M.empty('all', 'xyz'), 'Ничего не нашлось — попробуйте другое слово.');
same('пустое избранное — подсказка', M.empty('favorite', ''), 'Отметьте роли звёздочкой — они появятся здесь.');

console.log('\nкартинка роли (1.6.0)');
same('картинка из библиотеки — в пункт диалога', M.items([{ code: 'p', title: 'П', iconUrl: '/upload/p.png' }, { code: 'q', title: 'Q', iconUrl: 'javascript:1' }], {}, 'all', '', '').map((i) => i.iconUrl), ['/upload/p.png', '']);

console.log(`\n${count - errors.length} из ${count}`);
if (errors.length) { process.exit(1); }
