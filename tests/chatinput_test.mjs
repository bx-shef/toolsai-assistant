/**
 * Поле ввода чата (install/js/.../chat/chat.js, 1.6.0): готовые вопросы и
 * навык «с уточнениями» вставляются в поле ввода с выделенным первым
 * «[ … ]», а не отправляются сразу. Окно — DOM, поэтому prefill() гоняем на
 * подменном поле ввода, а привязку кнопок сверяем по исходнику.
 * Гоняет build.sh: node tests/chatinput_test.mjs.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const dir = path.join(root, 'install/js/shef/toolsaiassistant/chat');
const source = readFileSync(path.join(dir, 'chat.js'), 'utf8');

let count = 0;
const errors = [];
function same(what, actual, expected) {
  count++;
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  if (a === e) { console.log(`  OK   ${what}`); } else { errors.push(what); console.log(`  FAIL ${what}: получено ${a}, ожидалось ${e}`); }
}

console.log('привязка');
same('готовый вопрос — в поле ввода, не отправка', [source.includes("self.prefill(question)"), source.includes("self.send(question)")], [true, false]);
same('шаблон навыка — после info и смены роли', (source.match(/self\.prefillTemplate\(\)/g) || []).length, 2);

console.log('\nprefill');
const ns = {};
const context = { BX: { namespace: () => ns } };
context.window = context;
vm.createContext(context);
vm.runInContext(readFileSync(path.join(dir, 'view.js'), 'utf8'), context);
// Методы окна — из объекта-прототипа в chat.js: вырезаем prefill, prefillTemplate, nextPlaceholder
const pick = (name) => {
  const start = source.indexOf(`\t\t${name}: function(`);
  const body = source.slice(start + `\t\t${name}: `.length);
  let depth = 0;
  for (let i = body.indexOf('{'); i < body.length; i++) {
    if (body[i] === '{') { depth++; }
    if (body[i] === '}' && --depth === 0) { return body.slice(0, i + 1); }
  }
  return '';
};
const methods = vm.runInContext(`(function(View){ return {prefill: ${pick('prefill')}, prefillTemplate: ${pick('prefillTemplate')}, nextPlaceholder: ${pick('nextPlaceholder')}}; })({placeholder: BX.namespace().ChatView.placeholder})`, context);
const input = {
  value: '', selectionStart: 0, selectionEnd: 0, focused: false,
  focus() { this.focused = true; },
  setSelectionRange(a, b) { this.selectionStart = a; this.selectionEnd = b; },
};
const chat = Object.assign({ input, busy: false, info: { template: '' } }, methods);

chat.prefill('Разбери звонок с [клиент] за [период]');
same('текст в поле, фокус, выделено первое уточнение', [input.value, input.focused, input.selectionStart, input.selectionEnd], ['Разбери звонок с [клиент] за [период]', true, 17, 25]);
same('Tab — к следующему уточнению', [chat.nextPlaceholder(), input.selectionStart, input.selectionEnd], [true, 29, 37]);
same('Tab на последнем — снова к первому', [chat.nextPlaceholder(), input.selectionStart], [true, 17]);
chat.prefill('Что дальше?');
same('без уточнений — курсор в конце', [input.selectionStart, input.selectionEnd], [11, 11]);
same('без уточнений Tab — обычный', chat.nextPlaceholder(), false);

input.value = 'мой текст';
chat.info.template = 'Шаблон [x]';
chat.prefillTemplate();
same('шаблон не затирает то, что сотрудник уже пишет', input.value, 'мой текст');
input.value = '';
chat.prefillTemplate();
same('пустое поле — шаблон вставлен', [input.value, input.selectionStart, input.selectionEnd], ['Шаблон [x]', 7, 10]);

console.log(`\n${count - errors.length} из ${count}`);
if (errors.length) { process.exit(1); }
