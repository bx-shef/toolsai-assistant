/**
 * Ширина и место панели чата (install/js/.../chat/layout.js, 1.4.0, 1.9.0):
 * clamp ширины по окну, место под полосу мессенджера, колонка Air или поверх.
 * Гоняет build.sh: node tests/layout_test.mjs.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const ns = {};
vm.runInNewContext(readFileSync(path.join(root, 'install/js/shef/toolsaiassistant/chat/layout.js'), 'utf8'), { BX: { namespace: () => ns } });
const L = ns.ChatLayout;
const limits = { min: 320, max: 720 };

let count = 0;
const errors = [];
function same(what, actual, expected) {
  count++;
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  if (a === e) {
    console.log(`  OK   ${what}`);
  } else {
    errors.push(what);
    console.log(`  FAIL ${what}: получено ${a}, ожидалось ${e}`);
  }
}

console.log('\nширина по умолчанию');
same('1084 — 420 (не 62% окна)', L.compute(1084, 0, null, limits), { width: 420, right: 0, narrow: false });
same('1280 — 461', L.compute(1280, 0, null, limits).width, 461);
same('1920 — не шире 480', L.compute(1920, 0, null, limits).width, 480);
same('мусор в сохранённом — по умолчанию', L.compute(1280, 0, 'abc', limits).width, 461);

console.log('\nручная ширина');
same('в пределах — как есть', L.compute(1280, 0, '600', limits).width, 600);
same('меньше минимума — минимум', L.compute(1280, 0, 100, limits).width, 320);
same('больше максимума — 720', L.compute(1920, 0, 9999, limits).width, 720);
same('не шире окна без левой полосы', L.compute(780, 0, 9999, limits).width, 780 - 65);

console.log('\nузкий экран');
same('700 — на всю ширину', L.compute(700, 0, 600, limits), { width: 700, right: 0, narrow: true });
same('узко с полосой — до полосы', L.compute(800, 60, null, limits), { width: 740, right: 60, narrow: true });

console.log('\nполоса мессенджера');
same('у правого края — её ширина', L.inset(1084, { left: 1024, right: 1084, width: 60 }), 60);
same('нет полосы — 0', L.inset(1084, null), 0);
same('скрыта (ширина 0) — 0', L.inset(1084, { left: 1084, right: 1084, width: 0 }), 0);
same('сдвинута от края — 0', L.inset(1084, { left: 351, right: 411, width: 60 }), 0);
same('слишком широкая — не полоса', L.inset(1084, { left: 500, right: 1084, width: 584 }), 0);
same('панель встаёт левее полосы', L.compute(1084, 60, null, limits).right, 60);

console.log('\nрастягивание');
same('от курсора до полосы', L.dragWidth(1084, 60, 500), 524);

console.log('\nместо панели (1.9.0)');
same('Air, широкое окно — колонка шаблона', L.mode(1280, true), 'air');
same('Air, ровно 768 — колонка', L.mode(768, true), 'air');
same('Air, 767 — колонки нет (как в ядре), поверх', L.mode(767, true), 'overlay');
same('не Air — поверх', L.mode(1920, false), 'overlay');
same('мусор в ширине — поверх', L.mode('abc', true), 'overlay');

console.log('\nсужение колонки старого шаблона (1.9.0)');
same('панель и полоса', L.push(L.compute(1280, 60, '500', limits)), 560);
same('узкий экран — не сужаем', L.push(L.compute(700, 0, null, limits)), 0);
same('пусто — 0', L.push(null), 0);

console.log(`\n${count - errors.length} из ${count}`);
if (errors.length) { process.exit(1); }
