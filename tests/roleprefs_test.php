<?php declare(strict_types=1);

/**
 * Недавние и избранные роли (Chat\RolePrefs, RolePrefsStore, 1.4.0) и иконка
 * роли (Chat\RoleIcon): только открытые роли, без мусора, пределы списков.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/runtime.php';
require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Chat\RoleIcon;
use Shef\ToolsAiAssistant\Chat\RolePrefs;
use Shef\ToolsAiAssistant\Chat\RolePrefsStore;

Check::same('мусор — пусто', RolePrefs::normalize('x'), ['recent' => [], 'favorite' => []]);
Check::same('без дублей и плохих кодов', RolePrefs::normalize(['recent' => ['a', 'a', '<x>', 5, 'b']])['recent'], ['a', 'b']);
$p = RolePrefs::touch(['recent' => ['a', 'b']], 'b');
Check::same('выбранная — в начало недавних', $p['recent'], ['b', 'a']);
$p = ['recent' => []];
foreach(['1', '2', '3', '4', '5', '6'] as $c) { $p = RolePrefs::touch($p, $c); }
Check::same('недавних — не больше 5', $p['recent'], ['6', '5', '4', '3', '2']);
$f = RolePrefs::favorite([], 'a', true);
$f = RolePrefs::favorite($f, 'b', true);
Check::same('избранное — добавить', $f['favorite'], ['a', 'b']);
Check::same('избранное — убрать', RolePrefs::favorite($f, 'a', false)['favorite'], ['b']);
Check::same('плохой код — не меняет', RolePrefs::favorite($f, 'x y', true)['favorite'], ['a', 'b']);
Check::same('только открытые', RolePrefs::filter(['recent' => ['a', 'z'], 'favorite' => ['z', 'b']], ['a', 'b']), ['recent' => ['a'], 'favorite' => ['b']]);

RolePrefsStore::save(7, ['recent' => ['a'], 'favorite' => ['b', 'b']]);
Check::same('хранилище — своё у сотрудника', [RolePrefsStore::load(7), RolePrefsStore::load(8)], [['recent' => ['a'], 'favorite' => ['b']], ['recent' => [], 'favorite' => []]]);

Check::same('иконка из реестра', RoleIcon::of('x', 'o-money'), 'o-money');
Check::same('мусор в иконке — по коду', RoleIcon::of('shef.toolsaiassistant:supervisor', 'o-x" onclick'), 'o-crown');
Check::same('по коду роли', [RoleIcon::of('shef.toolsaiassistant:manager'), RoleIcon::of('shef.toolsaiassistant:deal.quality'), RoleIcon::of('shef.toolsaiassistant:bottlenecks'), RoleIcon::of('shef.toolsaiassistant:coach'), RoleIcon::of('shef.toolsaiassistant:customer')], ['o-person', 'o-check-list', 'o-bottleneck', 'o-idea-lamp', 'o-client-chat']);
Check::same('неизвестная — copilot', RoleIcon::of('other:thing'), 'o-copilot');

Check::finish();
