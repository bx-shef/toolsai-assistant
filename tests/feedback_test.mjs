/**
 * Окно оценки на странице «Оценки ответов» (install/js/.../feedback/feedback.js, 1.5.0).
 *
 * Вопрос, комментарий и ожидаемое — текст сотрудника, ответ — текст модели:
 * всё попадает в innerHTML окна у того, кто разбирает оценки. Проверяется
 * главное: текст экранирован, ответ размечен тем же markdown.js, что окно
 * чата (видно жирный, а не **звёздочки**), ссылки — только свой портал.
 * Гоняет build.sh: node tests/feedback_test.mjs.
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const md = require(path.join(root, 'install/js/shef/toolsaiassistant/chat/markdown.js'));
const fb = require(path.join(root, 'install/js/shef/toolsaiassistant/feedback/feedback.js'));

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

const row = {
  id: 1, created: '17.10.2026 10:00', user: 'Иван <b>', role: 'a:b', roleTitle: 'Роль"',
  promptUrl: '/shef/toolsai/?section=promptlib&tab=roles&code=a%3Ab', chat: 'запись CRM', recordUrl: '/crm/deal/details/7/',
  rating: -1, ratingTitle: 'Не помогло', status: 'new',
  question: '<script>alert(1)</script>', answer: '**Жирно** <img src=x onerror=alert(1)>', comment: '"><svg onload=1>', expected: '</textarea><script>x</script>',
};
const statuses = { new: 'Новая' };

console.log('\nокно оценки');
const html = fb.detailHtml(row, md, statuses, true);
same('вопрос экранирован', html.includes('<script>alert(1)</script>'), false);
same('ответ: markdown — жирный, без звёздочек', [html.includes('<strong>Жирно</strong>') || html.includes('<b>Жирно</b>'), html.includes('**Жирно**')].join(), 'true,false');
same('ответ: сырой HTML модели — текстом', html.includes('<img'), false);
same('комментарий и имя экранированы', [html.includes('<svg'), html.includes('Иван <b>')].join(), 'false,false');
same('ожидаемое не закрывает textarea', html.includes('</textarea><script>'), false);
same('ссылки на запись и на промпт роли', [html.includes('href="/crm/deal/details/7/"'), html.includes('править промпт роли')].join(), 'true,true');
same('без права — без поля ввода', fb.detailHtml(row, md, statuses, false).includes('<textarea'), false);
same('чужой адрес записи — без ссылки', fb.detailHtml({ ...row, recordUrl: '//evil.example/', promptUrl: 'javascript:alert(1)' }, md, statuses, false).includes('href="//evil') , false);
same('javascript: — не ссылка', fb.localUrl('javascript:alert(1)'), null);

console.log('\nвыгрузка');
same('имя файла', fb.fileName(new Date(2026, 9, 7)), 'assistant-feedback-cases-2026-10-07.json');
same('итог словами', fb.exportSummary({ exported: [12], skipped: { 13: 'no_expected', 14: 'no_expected' }, reasons: { no_expected: 'не заполнено' } }), 'Кейсов выгружено: 1. Пропущено (не заполнено): №13, №14.');

console.log(`Проверок пройдено: ${count - errors.length}`);
if (errors.length) {
  console.log(`Провалено: ${errors.length}`);
  process.exit(1);
}
