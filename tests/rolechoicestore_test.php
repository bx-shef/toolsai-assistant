<?php declare(strict_types=1);

/**
 * Запомненная роль: одна строка на сотрудника и чат — смена роли обновляет
 * её, а не добавляет вторую; гонка вставки — один повтор, не бесконечный.
 * ORM — в памяти (tests/stub/runtime.php).
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/runtime.php';
require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ORM\Data\DataManager;
use Shef\ToolsAiAssistant\Chat\Model\RoleChoiceTable;
use Shef\ToolsAiAssistant\Chat\RoleChoiceStore;

$store = new RoleChoiceStore();
$rows = static fn(): array => array_map(static fn(array $r): string => $r['USER_ID'].'|'.$r['CHAT_KEY'].'|'.$r['ROLE_CODE'], array_values(DataManager::$rows[RoleChoiceTable::class] ?? []));

Check::same('не выбирал — null', $store->get(1, 'general'), null);
$store->save(1, 'general', 'a:manager');
$store->save(1, 'record:2:5', 'a:coach');
$store->save(2, 'general', 'a:customer');
Check::same('строки на сотрудника и чат', $rows(), ['1|general|a:manager', '1|record:2:5|a:coach', '2|general|a:customer']);
$store->save(1, 'general', 'a:supervisor');
$store->save(1, 'general', 'a:supervisor');
Check::same('смена роли — обновление без дублей', $rows(), ['1|general|a:supervisor', '1|record:2:5|a:coach', '2|general|a:customer']);
Check::same('читается своя', [$store->get(1, 'general'), $store->get(1, 'record:2:5'), $store->get(3, 'general')], ['a:supervisor', 'a:coach', null]);
$store->save(0, 'general', 'a:manager');
Check::same('без сотрудника — не пишется', count($rows()), 3);

DataManager::$failAdd[RoleChoiceTable::class] = 1;
$store->save(4, 'general', 'a:manager');
Check::same('гонка вставки — один повтор и запись', $store->get(4, 'general'), 'a:manager');
DataManager::$failAdd[RoleChoiceTable::class] = 5;
Check::throws('повторная неудача — наружу, без бесконечной рекурсии', SqlQueryException::class, static fn() => $store->save(5, 'general', 'a:manager'));

Check::finish();
