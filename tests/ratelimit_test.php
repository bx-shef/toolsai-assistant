<?php declare(strict_types=1);

/** Ограничение частоты: скользящая минута, 0 — без ограничения, отметки не растут. */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Chat\RateLimit;

Check::same('первый — можно', RateLimit::hit([], 100, 2), ['allowed' => true, 'stamps' => [100]]);
Check::same('второй — можно', RateLimit::hit([100], 110, 2), ['allowed' => true, 'stamps' => [100, 110]]);
Check::same('третий за минуту — нельзя', RateLimit::hit([100, 110], 120, 2)['allowed'], false);
Check::same('старые выпадают из окна', RateLimit::hit([100, 110], 161, 2), ['allowed' => true, 'stamps' => [110, 161]]);
Check::same('ровно 60 секунд — уже вне окна', RateLimit::hit([100, 110], 170, 2)['stamps'], [170]);
Check::same('0 — без ограничения и без хранения', RateLimit::hit(array_fill(0, 50, 100), 100, 0), ['allowed' => true, 'stamps' => []]);
Check::same('отметки из будущего и мусор — не считаются', RateLimit::hit([999, 'x'], 100, 1)['allowed'], true);
Check::same('хранится не больше лимита', count(RateLimit::hit([95, 96, 97, 98, 99], 100, 3)['stamps']) <= 3, true);

Check::finish();
