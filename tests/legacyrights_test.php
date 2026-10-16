<?php declare(strict_types=1);

/** Перенос прав чата shef.toolsai до 1.50.0: кому был выдан чат — тем и выдаём. */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Access\LegacyRights;

$permissions = [
	['ROLE_ID' => '1', 'ACTION' => 'kb.read'],
	['ROLE_ID' => '2', 'ACTION' => 'assistant.use'],
	['ROLE_ID' => '3', 'ACTION' => 'assistant.panel'],
	['ROLE_ID' => '3', 'ACTION' => 'view.use'],
];
$codes = [
	['ROLE_ID' => '1', 'ACCESS_CODE' => 'DR1'],
	['ROLE_ID' => '2', 'ACCESS_CODE' => 'DR5'],
	['ROLE_ID' => '2', 'ACCESS_CODE' => 'U7'],
	['ROLE_ID' => '3', 'ACCESS_CODE' => 'U7'],
	['ROLE_ID' => '3', 'ACCESS_CODE' => 'G2'],
];

Check::same('роли с assistant.use и assistant.panel, без повторов и чужих кодов', LegacyRights::plan($permissions, $codes), ['DR5', 'U7']);
Check::same('чата никому не давали — пусто', LegacyRights::plan([['ROLE_ID' => 1, 'ACTION' => 'kb.read']], $codes), []);
Check::same('пустые таблицы — пусто', LegacyRights::plan([], []), []);

Check::finish();
