<?php declare(strict_types=1);

/**
 * Пункты меню шестерёнки вида «ИИ» shef.toolsai (OnViewSettingsMenuBuild,
 * 1.3.2): по контракту ViewSettingsMenuBuildEvent, только по правам.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/runtime.php';
require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Internals\Integration\ToolsAi\EventHandler\OnViewSettingsMenuBuildEventHandler as Handler;

$settings = ['text' => 'ИИ чат с ролями: настройки', 'url' => '/bitrix/admin/settings.php?mid=shef.toolsaiassistant&lang=ru'];
$feedback = ['text' => 'Оценки ответов', 'url' => '/shef/toolsaiassistant/feedback/'];

Check::same('право W и оценки — оба пункта', Handler::items('W', true), [$settings, $feedback]);
Check::same('право R — настройки', Handler::items('R', false), [$settings]);
Check::same('право D — без настроек', Handler::items('D', false), []);
Check::same('нет права на модуль, но оценки — только оценки', Handler::items('', true), [$feedback]);

// Контракт вида: путь портала с одной «/», без пробелов и кавычек; текст до 60 знаков
foreach([$settings, $feedback] as $item)
{
	Check::same('годен для вида: '.$item['text'], [(bool)preg_match('~^/(?![/\\\\])[^\s"\'<>\\\\]*$~', $item['url']), mb_strlen($item['text']) <= 60], [true, true]);
}

$GLOBALS['USER'] = new CUser(0, false);
$GLOBALS['APPLICATION'] = new class {
	public function GetGroupRight(string $module): string { return $module === 'shef.toolsaiassistant' ? 'R' : 'D'; }
};
$result = Handler::handle(new Event('shef.toolsai', 'OnViewSettingsMenuBuild', ['entityTypeId' => 2, 'categoryId' => 0]));
Check::same('handle: SUCCESS от модуля, право R без оценок', [$result->getType(), $result->getModuleId(), $result->getParameters()['items']], [EventResult::SUCCESS, 'shef.toolsaiassistant', [$settings]]);

$GLOBALS['APPLICATION'] = new class {
	public function GetGroupRight(string $module): string { throw new \RuntimeException('сбой'); }
};
Check::same('сбой — пусто, вид не ломается', Handler::handle(new Event('shef.toolsai', 'OnViewSettingsMenuBuild', []))->getParameters()['items'], []);
unset($GLOBALS['APPLICATION']);

Check::finish();
