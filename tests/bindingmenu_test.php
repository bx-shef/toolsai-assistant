<?php declare(strict_types=1);

/**
 * «✦ Спросить ИИ» в меню-шестерёнке карточки CRM (intranet:onBuildBindingMenu,
 * 1.3.2): формат пункта для intranet/lib/binding/menu.php (getBindings(),
 * getValidItems()), раздел crm_detail, подписка в .settings.php.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/runtime.php';
require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Internals\Integration\Intranet\EventHandler\OnBuildBindingMenuEventHandler as Handler;
use Shef\ToolsAiAssistant\Panel\SidePanel;

$places = Handler::places(['deal', 'lead', 'dynamic_128', 'deal', 'Bad Code', 5]);
Check::same('одно место', count($places), 1);
Check::same('ключи места — bindings и items (их требует intranet)', array_keys($places[0]), ['bindings', 'items']);
Check::same('раздел — меню карточки CRM', array_keys($places[0]['bindings']), ['crm_detail']);
Check::same('коды карточек без повторов и мусора', $places[0]['bindings']['crm_detail']['include'], ['deal', 'lead', 'dynamic_128']);
$item = $places[0]['items'][0];
Check::same('id и text — строки (getValidItems)', [is_string($item['id']), is_string($item['text'])], [true, true]);
Check::same('текст пункта', $item['text'], '✦ Спросить ИИ');
Check::same('клик — чат по открытой карточке', str_starts_with($item['onclick'], 'if(window.ShefAiChat&&window.ShefAiChat.openCurrent){window.ShefAiChat.openCurrent();}'), true);
Check::same('в onclick нет сырых кавычек и угловых скобок из текста', preg_match('/[<>\']/', $item['onclick']), 0);
Check::same('пусто — без места', Handler::places([]), []);
Check::same('штатные карточки', Handler::ENTITIES, ['deal', 'lead', 'contact', 'company', 'quote', 'smart_invoice']);
Check::same('SidePanel::openCurrentJs() = onclick', SidePanel::openCurrentJs(), $item['onclick']);

$settings = require $root.'/.settings.php';
$found = array_values(array_filter($settings['installEvents']['value'], static fn(array $e): bool => $e['from'] === ['module' => 'intranet', 'event' => 'onBuildBindingMenu']));
Check::same('подписка в .settings.php', [count($found), $found[0]['to']['class'] ?? '', !empty($found[0]['isCompatible'])], [1, '\\'.Handler::class, false]);

Check::finish();
