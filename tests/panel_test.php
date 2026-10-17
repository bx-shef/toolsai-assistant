<?php declare(strict_types=1);

/**
 * Панель справа: ширина, onclick строки «✦ Спросить ИИ», настройки окна.
 * То, что уходит в атрибут onclick и в <script>, не должно его закрыть.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Panel\SidePanel;
use Shef\ToolsAiAssistant\Public\Service\ChatPanel;

Check::group('ширина');

Check::same('мусор — по умолчанию', SidePanel::width('abc'), 440);
Check::same('меньше минимума', SidePanel::width(10), 320);
Check::same('больше максимума', SidePanel::width('9999'), 720);
Check::same('в пределах', SidePanel::width('500'), 500);

Check::group('чат по записи');

$js = SidePanel::openRecordJs(2, 15, 'Окна "ПВХ" </script><b>');
Check::same('вызов window.ShefAiChat.open', str_starts_with($js, 'window.ShefAiChat&&window.ShefAiChat.open({'), true);
Check::same('кавычки, <, > экранированы', [str_contains($js, '"ПВХ"'), str_contains($js, '</script>'), str_contains($js, '<b>')], [false, false, false]);
$data = json_decode(substr($js, strlen('window.ShefAiChat&&window.ShefAiChat.open('), -1), true);
Check::same('данные читаются обратно', $data, ['entityTypeId' => 2, 'id' => 15, 'title' => 'Окна "ПВХ" </script><b>']);
Check::same('длинное название обрезано', mb_strlen(json_decode(substr(SidePanel::openRecordJs(2, 1, str_repeat('я', 300)), 42, -1), true)['title']), 120);
Check::same('публичный API отдаёт то же', ChatPanel::openRecordJs(2, 15, 'x'), SidePanel::openRecordJs(2, 15, 'x'));

Check::group('настройки окна');

$settings = SidePanel::settings('  Сделки  ', 'btn"<x>');
Check::same('id кнопки — только безопасные символы', $settings['button'], 'btnx');
Check::same('тема — обрезана', $settings['context'], 'Сделки');
Check::same('пределы ширины и слой', [$settings['min'], $settings['max'], $settings['def'], $settings['z']], [320, 720, 440, 990]);
Check::same('пустой id — по умолчанию', SidePanel::settings('', '<>')['button'], SidePanel::BUTTON_ID);
$script = SidePanel::initScript(SidePanel::settings('</script><script>alert(1)</script>'));
Check::same('скрипт запуска не закрывается данными', substr_count($script, '</script>'), 1);

Check::finish();
