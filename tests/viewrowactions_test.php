<?php declare(strict_types=1);

/**
 * «✦ Спросить ИИ» в меню строки вида «ИИ» shef.toolsai (OnViewRowActionsBuild):
 * пункт — только тем, у кого есть чат; onclick экранирован; ответ — от этого модуля.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/runtime.php';
require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Internals\Integration\ToolsAi\EventHandler\OnViewRowActionsBuildEventHandler;
use Shef\ToolsAiAssistant\Panel\SidePanel;

$event = static fn(int $type, int $id, string $title): Event => new Event('shef.toolsai', 'OnViewRowActionsBuild', ['entityTypeId' => $type, 'id' => $id, 'title' => $title]);

$GLOBALS['USER'] = new CUser(5, true);
$result = OnViewRowActionsBuildEventHandler::handle($event(2, 15, 'Окна "ПВХ"'));
Check::same('SUCCESS от этого модуля', [$result->getType(), $result->getModuleId()], [EventResult::SUCCESS, 'shef.toolsaiassistant']);
Check::same('пункт «✦ Спросить ИИ» по записи', $result->getParameters()['actions'], [['text' => '✦ Спросить ИИ', 'onclick' => SidePanel::openRecordJs(2, 15, 'Окна "ПВХ"')]]);
Check::same('кавычки названия экранированы', str_contains($result->getParameters()['actions'][0]['onclick'], '"ПВХ"'), false);
Check::same('без названия — #id', $result = OnViewRowActionsBuildEventHandler::handle($event(2, 7, ' '))->getParameters()['actions'][0]['onclick'], SidePanel::openRecordJs(2, 7, '#7'));
Check::same('без записи — пусто', OnViewRowActionsBuildEventHandler::handle($event(0, 15, 'x'))->getParameters()['actions'], []);

$GLOBALS['USER'] = new CUser(0, false);
Check::same('без права на чат — пусто', OnViewRowActionsBuildEventHandler::handle($event(2, 15, 'x'))->getParameters()['actions'], []);

Check::finish();
