/**
 * Разбор ответа модели в окне чата (install/js/.../chat/markdown.js).
 *
 * Ответ модели попадает в innerHTML окна, открытого на странице CRM
 * сотрудника. Сырой HTML модели и ссылка javascript: здесь — это XSS от имени
 * сотрудника, поэтому проверяется главное: всё экранировано, ссылки — только
 * на свой портал или http(s). Гоняет build.sh: node tests/markdown_test.mjs.
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const md = require(path.join(root, 'install/js/shef/toolsaiassistant/chat/markdown.js'));

let count = 0;
const errors = [];
function same(what, actual, expected) {
  count++;
  if (actual === expected) {
    console.log(`  OK   ${what}`);
  } else {
    errors.push(what);
    console.log(`  FAIL ${what}: получено ${JSON.stringify(actual)}, ожидалось ${JSON.stringify(expected)}`);
  }
}

console.log('\nэкранирование');
same('сырой HTML модели — текстом', md.render('<img src=x onerror=alert(1)>'), '<p>&lt;img src=x onerror=alert(1)&gt;</p>');
same('кавычки', md.render('"a" \'b\''), '<p>&quot;a&quot; &#39;b&#39;</p>');
same('HTML в коде — текстом', md.render('`<b>`'), '<p><code>&lt;b&gt;</code></p>');
same('HTML в блоке кода — текстом', md.render('```\n<script>x</script>\n```'), '<pre><code>&lt;script&gt;x&lt;/script&gt;</code></pre>');

console.log('\nссылки');
same('путь своего портала', md.render('[Сделка](/crm/deal/details/5/)'), '<p><a href="/crm/deal/details/5/" data-shef-link="1">Сделка</a></p>');
same('& в адресе — один раз', md.render('[x](/a/?b=1&c=2)'), '<p><a href="/a/?b=1&amp;c=2" data-shef-link="1">x</a></p>');
same('https', md.render('[Док](https://example.com/a)'), '<p><a href="https://example.com/a" data-shef-link="1">Док</a></p>');
same('javascript: — без ссылки', md.render('[x](javascript:void)').includes('href'), false);
same('data: — без ссылки', md.render('[x](data:text/html,1)').includes('href'), false);
same('//чужой сайт — без ссылки', md.render('[x](//evil.example/)').includes('href'), false);
same('кавычка в адресе не выходит из атрибута', md.render('[x](/a"onclick="alert(1))').includes('onclick="'), false);
same('safeUrl: путь', md.safeUrl('/notes/1/'), '/notes/1/');
same('safeUrl: javascript', md.safeUrl('javascript:alert(1)'), null);
same('safeUrl: обратный слэш', md.safeUrl('/\\evil.example'), null);

console.log('\nразметка');
same('жирный и курсив', md.render('**важно** и *тихо*'), '<p><strong>важно</strong> и <em>тихо</em></p>');
same('маркированный список', md.render('- раз\n- два'), '<ul><li>раз</li><li>два</li></ul>');
same('нумерованный список', md.render('1. раз\n2) два'), '<ol><li>раз</li><li>два</li></ol>');
same('абзацы и перенос строки', md.render('a\nb\n\nc'), '<p>a<br>b</p><p>c</p>');
same('заголовок — жирной строкой', md.render('## Итог'), '<p><strong>Итог</strong></p>');
same('пусто', md.render(''), '');
same('незакрытый блок кода закрыт', md.render('```\nx'), '<pre><code>x</code></pre>');

console.log('');
if (errors.length) {
  console.log(`Провалено ${errors.length} из ${count}`);
  process.exit(1);
}
console.log(`Проверок пройдено: ${count}`);
