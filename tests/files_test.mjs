/**
 * Файлы в чате (install/js/.../chat/files.js, 1.8.0): текст из настоящих
 * DOCX, XLSX, PPTX (собраны python-docx, openpyxl, python-pptx), CSV в
 * windows-1251; PDF — подставным pdf.js (настоящий ui.pdfjs ядра — в браузере);
 * отказы с понятной причиной, пределы.
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const require = createRequire(import.meta.url);
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const F = require(path.join(root, 'install/js/shef/toolsaiassistant/chat/files.js'));
const fixture = name => { const b = readFileSync(path.join(root, 'tests/fixtures/files', name)); return { name, size: b.length, bytes: () => new Uint8Array(b) }; };

let count = 0;
const errors = [];
function same(what, actual, expected) {
  count++;
  const a = JSON.stringify(actual), e = JSON.stringify(expected);
  if (a === e) { console.log(`  OK   ${what}`); } else { errors.push(what); console.log(`  FAIL ${what}: получено ${a}, ожидалось ${e}`); }
}
const fail = p => p.then(() => 'нет отказа', e => e instanceof F.FileError ? e.message : 'не FileError: ' + e);

console.log('\nформаты');
const d = await F.extract(fixture('kp.docx'));
same('DOCX: вид, заголовок, абзац', [d.kind, d.text.startsWith('Коммерческое предложение № 15'), d.text.includes('Срок — 14 дней')], ['docx', true, true]);
same('DOCX: таблица строками через « | »', d.text.includes('Окно 1200×1400 | 4 | 350 BYN'), true);
same('DOCX: сущности XML раскрыты', d.text.includes('Итого: 1 720 BYN & скидка 5% <для повторных>'), true);
const x = await F.extract(fixture('price.xlsx'));
same('XLSX: листы по имени, строки, числа, пустые ячейки на месте', x.text.split('\n'), ['## Лист «Прайс»', 'Артикул | Наименование | Цена', 'A-1 | Окно 1200×1400 | 350', 'A-2 | Подоконник |  |  | Нет в наличии', '## Лист «Скидки»', 'От 10 шт | 5%']);
const p = await F.extract(fixture('deck.pptx'));
same('PPTX: слайды по порядку', p.text, '## Слайд 1\nПочему мы\nГарантия 5 лет\nМонтаж за 1 день\n\n## Слайд 2\nЦены\nОт 350 BYN');
// windows-1251 собирается здесь: build.sh требует UTF-8 у всех файлов репозитория
const cp1251 = s => Uint8Array.from([...s].map(ch => { const k = ch.charCodeAt(0); return k === 0x401 ? 0xa8 : k === 0x451 ? 0xb8 : k >= 0x410 && k <= 0x44f ? k - 0x350 : k; }));
const csv = cp1251('Клиент;Телефон;Статус\nИванов;+375291112233;Новый\n');
const c = await F.extract({ name: 'leads.csv', size: csv.length, bytes: () => csv });
same('CSV в windows-1251 — по-русски', [c.kind, c.text.split('\n')[1]], ['csv', 'Иванов;+375291112233;Новый']);
const utf = await F.extract({ name: 'a.txt', size: 6, bytes: () => new TextEncoder().encode('﻿Привет') });
same('TXT в UTF-8, без BOM', utf.text, 'Привет');

console.log('\nPDF (подставной pdf.js)');
const fakePdf = (pages, numPages) => bytes => Promise.resolve({ numPages: numPages || pages.length, getPage: i => Promise.resolve({ getTextContent: () => Promise.resolve({ items: pages[i - 1] }) }) });
const pdf = await F.extract(fixture('kp.pdf'), { loadPdf: fakePdf([[{ str: 'Коммерческое', hasEOL: false }, { str: 'предложение', hasEOL: true }], [{ str: 'Итого', hasEOL: false }]]) });
same('PDF: страницы через пустую строку', [pdf.kind, pdf.text], ['pdf', 'Коммерческое предложение\n\nИтого']);
same('PDF-скан без текста — отказ', await fail(F.extract(fixture('kp.pdf'), { loadPdf: fakePdf([[], []]) })), 'в PDF нет текста — похоже на скан');
same('PDF длиннее 60 страниц — пометка', (await F.extract(fixture('kp.pdf'), { loadPdf: fakePdf(Array.from({ length: 60 }, () => [{ str: 'x', hasEOL: false }]), 90) })).text.endsWith('(больше 60)'), true);
same('PDF без pdf.js — отказ', await fail(F.extract(fixture('kp.pdf'))), 'чтение PDF недоступно на этой странице');

const it = (str, x, y, width) => ({ str, transform: [10, 0, 0, 10, x, y], width, height: 10 });
same('PDF по координатам: строки сверху вниз, куски слева направо, пробел по зазору', F.pdfPageText({ items: [it('Срок', 0, 700, 20), it('«Ромашка»', 30, 720, 40), it('ООО', 0, 720, 25), it('14 дней', 25, 700, 30), it(',', 70, 720, 2)] }), 'ООО «Ромашка»,\nСрок 14 дней');
same('PDF без координат — по порядку потока', F.pdfPageText({ items: [{ str: 'а', hasEOL: true }, { str: 'б' }] }), 'а\nб');

console.log('\nотказы и пределы');
same('картинка', await fail(F.extract({ name: 'скан.JPG', size: 1, bytes: () => new Uint8Array(1) })), 'картинки пока не читаю — нужен текст: PDF, Word, Excel');
same('старый .doc — подсказка сохранить как DOCX', F.kindOf('договор.doc').error, 'старый формат — сохраните как DOCX');
same('неизвестное', F.kindOf('a.exe').error, 'такой файл не читаю — нужен PDF, DOCX, XLSX, PPTX, CSV или TXT');
same('больше 15 МБ — до чтения', await fail(F.extract({ name: 'a.txt', size: 16 * 1024 * 1024, bytes: () => { throw new Error('не читать'); } })), 'больше 15 МБ');
same('битый DOCX', await fail(F.extract({ name: 'a.docx', size: 3, bytes: () => new Uint8Array([1, 2, 3]) })), 'файл повреждён или это не документ Office');
same('xlsx, переименованный в docx', await fail(F.extract({ ...fixture('price.xlsx'), name: 'a.docx' })), 'это не документ Word');
same('пустой текст', await fail(F.extract({ name: 'a.txt', size: 2, bytes: () => new TextEncoder().encode('  ') })), 'в файле нет текста');
const long = F.clip('строка\n'.repeat(10000));
same('длинный текст — до 30 000 знаков по границе строки, пометка', [long.text.length <= F.MAX_CHARS, long.text.endsWith('строка'), long.truncated], [true, true, true]);
same('сколько ещё можно добавить: до 5', [F.room(0, 7), F.room(3, 7), F.room(5, 1)], [5, 2, 0]);

console.log('');
if (errors.length) { console.log(`Провалено ${errors.length} из ${count}`); process.exit(1); }
console.log(`Проверок пройдено: ${count}`);
