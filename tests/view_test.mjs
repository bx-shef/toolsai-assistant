/**
 * Вид переписки (install/js/.../chat/view.js, 1.4.0): стороны, автор и иконка
 * роли, время, приветственная карточка и ЭКРАНИРОВАНИЕ вопроса и ответа.
 * Гоняет build.sh: node tests/view_test.mjs.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const ns = {};
const context = { BX: { namespace: () => ns } };
context.window = context;
vm.createContext(context);
for (const file of ['markdown.js', 'view.js']) {
  vm.runInContext(readFileSync(path.join(root, 'install/js/shef/toolsaiassistant/chat', file), 'utf8'), context);
}
if (!ns.Markdown && context.BX.Shef) { ns.Markdown = context.BX.Shef.ToolsAiAssistant.Markdown; }
const V = ns.ChatView;

let count = 0;
const errors = [];
function same(what, actual, expected) {
  count++;
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  if (a === e) { console.log(`  OK   ${what}`); } else { errors.push(what); console.log(`  FAIL ${what}: получено ${a}, ожидалось ${e}`); }
}
const roles = [{ code: 'shef.toolsaiassistant:manager', title: 'Помощник <b>менеджера</b>', description: 'Про сделку', icon: 'o-person' }];

console.log('\nмаркдаун подключён');
same('Markdown есть', !!ns.Markdown, true);

console.log('\nвопрос сотрудника');
const mine = V.message({ role: 'user', text: '<img src=x onerror=alert(1)>\nвторая', time: Date.UTC(2026, 9, 17, 9, 5) }, roles, 0);
same('справа, без автора', [mine.side, mine.author, mine.icon], ['right', '', '']);
same('HTML экранирован, перенос — <br>', mine.html, '&lt;img src=x onerror=alert(1)&gt;<br>вторая');
same('время ЧЧ:ММ', mine.time, '09:05');

console.log('\nответ помощника');
const ans = V.message({ role: 'assistant', text: '**Да** <script>x()</script>', roleCode: 'shef.toolsaiassistant:manager' }, roles, 0);
same('слева, автор — роль, иконка роли', [ans.side, ans.author, ans.icon], ['left', 'Помощник <b>менеджера</b>', 'o-person']);
same('script не проходит', ans.html.includes('<script>'), false);
same('жирный — размечен', ans.html.includes('<strong>Да</strong>'), true);
same('без времени — пусто', ans.time, '');
same('неизвестная роль — «ИИ-помощник», иконка по умолчанию', [V.message({ role: 'assistant', text: '', roleCode: 'x' }, roles).author, V.message({ role: 'assistant', text: '', roleCode: 'x' }, roles).icon], ['ИИ-помощник', 'o-copilot']);
same('иконка — только код, не класс с мусором', V.iconOf({ icon: 'o-x" onclick="1' }), 'o-copilot');

console.log('\nприветствие');
const w = V.welcome(roles[0], { record: 'Окна ПВХ', kb: true, examples: ['Что дальше?'] });
same('«Привет! Я — роль»', w.title, 'Привет! Я — Помощник <b>менеджера</b>');
same('описание роли', w.text, 'Про сделку');
same('по записи и базе', w.scope, 'Отвечаю по истории записи «Окна ПВХ»: звонки, письма, чаты, дела и статьи базы знаний.');
same('готовые вопросы', w.examples, ['Что дальше?']);
same('без роли', V.welcome(null, {}).title, 'ИИ-помощник');

console.log(`\n${count - errors.length} из ${count}`);
if (errors.length) { process.exit(1); }
