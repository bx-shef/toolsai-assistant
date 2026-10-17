<?php declare(strict_types=1);

/**
 * «✦ ИИ: чат» в шапке вида «ИИ» shef.toolsai (OnViewToolbarBuild, 1.3.1):
 * кнопка по контракту ViewToolbarBuildEvent, только где чат включён и
 * открыт; своя кнопка OnProlog в виде при событии не ставится.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/runtime.php';
require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Internals\Integration\ToolsAi\EventHandler\OnViewToolbarBuildEventHandler;
use Shef\ToolsAiAssistant\Panel\SidePanel;

$button = SidePanel::viewToolbarButton();
Check::same('кнопка по контракту: text, onclick, id', array_keys($button), ['text', 'onclick', 'id']);
Check::same('текст со звёздочкой, до 40 знаков', [$button['text'], mb_strlen($button['text']) <= 40], ['✦ ИИ: чат', true]);
Check::same('id — по правилу вида и не id кнопки CRM', [(bool)preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $button['id']), $button['id'] !== SidePanel::BUTTON_ID], [true, true]);
Check::same('onclick — переключить чат', $button['onclick'], 'window.ShefAiChat&&window.ShefAiChat.toggle(null)');
Check::same('иконка свёрнутого режима = Icon::AI ядра', SidePanel::COLLAPSED_ICON, 'ui-btn-icon-ai ui-icon-set__scope');

$default = Config::fromValues([]);
Check::same('вид «ИИ» по умолчанию — кнопка', OnViewToolbarBuildEventHandler::buttons($default, '/shef/toolsai/view/?type=2'), [$button]);
Check::same('вида нет в местах — без кнопки', OnViewToolbarBuildEventHandler::buttons(Config::fromValues([Config::OPTION_PATHS => '/crm/']), '/shef/toolsai/view/'), []);
Check::same('чат выключен — без кнопки', OnViewToolbarBuildEventHandler::buttons(Config::fromValues([Config::OPTION_ENABLED => 'N']), '/shef/toolsai/view/'), []);

$GLOBALS['USER'] = new CUser(0, false);
$result = OnViewToolbarBuildEventHandler::handle(new Event('shef.toolsai', 'OnViewToolbarBuild', ['entityTypeId' => 2, 'categoryId' => 0]));
Check::same('без права — SUCCESS от модуля, пусто', [$result->getType(), $result->getModuleId(), $result->getParameters()['buttons']], [EventResult::SUCCESS, 'shef.toolsaiassistant', []]);

Check::same('вид при событии — своей кнопки нет', SidePanel::needsOwnToolbarButton('/shef/toolsai/view/?type=2', true), false);
Check::same('вид без события (toolsai < 1.53) — своя кнопка', SidePanel::needsOwnToolbarButton('/shef/toolsai/view/', false), true);
Check::same('CRM — своя кнопка штатным Toolbar API', SidePanel::needsOwnToolbarButton('/crm/deal/', true), true);

Check::finish();
