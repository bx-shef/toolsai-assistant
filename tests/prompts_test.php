<?php declare(strict_types=1);

/**
 * Меню промптов и виджеты чата (1.7.0): места окна, типовые запросы модуля в
 * реестр shef.toolsai, каталог виджетов (встроенные и модулей), подсказка модели.
 *
 * Типовые запросы проверяются настоящим Prompt\Definition::fromArray()
 * shef.toolsai, если его исходники рядом (TOOLSAI_DIR, ../toolsai): реестр
 * молча отбрасывает негодное описание — запрос просто не появился бы в меню.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Chat\Record;
use Shef\ToolsAiAssistant\Chat\WidgetCatalog;
use Shef\ToolsAiAssistant\Internals\Integration\ToolsAi\EventHandler\OnPromptPlacesCollectEventHandler;
use Shef\ToolsAiAssistant\Main\Constants;
use Shef\ToolsAiAssistant\Prompt\ChatPlaces;
use Shef\ToolsAiAssistant\Prompt\TypicalPrompts;

Check::group('места окна');
Check::same('карточка, список, общий, справка', [
	ChatPlaces::forWindow(new Record(2, 1), '', ''),
	ChatPlaces::forWindow(null, 'list', ''),
	ChatPlaces::forWindow(null, '', ''),
	ChatPlaces::forWindow(new Record(2, 1), 'list', 'docs'),
], [
	['shef.toolsaiassistant:chat', 'shef.toolsaiassistant:chat.card'],
	['shef.toolsaiassistant:chat', 'shef.toolsaiassistant:chat.list'],
	['shef.toolsaiassistant:chat'],
	[],
]);
Check::same('запись важнее вида «список»', ChatPlaces::forWindow(new Record(2, 1), 'list', ''), ['shef.toolsaiassistant:chat', 'shef.toolsaiassistant:chat.card']);
Check::same('вид из запроса — только list', [ChatPlaces::view('list'), ChatPlaces::view('card'), ChatPlaces::view(['list'])], ['list', '', '']);
Check::same('группа по местам', [ChatPlaces::group(['shef.toolsaiassistant:chat.card', 'card']), ChatPlaces::group(['shef.toolsaiassistant:chat.list']), ChatPlaces::group(['assistant'])], ['card', 'list', 'chat']);
$result = OnPromptPlacesCollectEventHandler::handle(new Event('shef.toolsai', 'OnPromptPlacesCollect'));
Check::same('места для библиотеки shef.toolsai: id без модуля, группа, владелец — модуль', [$result->getType(), $result->getModuleId(), array_column($result->getParameters()['places'], 'id')], [EventResult::SUCCESS, Constants::MODULE_ID, ['chat', 'chat.list', 'chat.card']]);

Check::group('типовые запросы');
$raw = TypicalPrompts::raw();
$broken = [];
foreach($raw as $p)
{
	foreach([
		'код модуля' => str_starts_with($p['code'], Constants::MODULE_ID.':prompt.'),
		'навык без роли' => $p['kind'] === 'skill' && $p['parent'] === '',
		'место — чат помощника' => $p['places'] !== [] && array_diff($p['places'], array_map([ChatPlaces::class, 'full'], array_keys(ChatPlaces::TITLES))) === [],
		'вид plain|template' => in_array($p['type'], ['plain', 'template'], true),
		'с уточнениями — есть [ … ]' => $p['type'] !== 'template' || preg_match('/\[[^\[\]\r\n]{1,200}\]/u', $p['text']) === 1,
		'название до 120' => $p['title'] !== '' && mb_strlen($p['title']) <= 120,
	] as $what => $ok)
	{
		if(!$ok) { $broken[] = $p['code'].': '.$what; }
	}
}
Check::same('все типовые запросы проходят правила', $broken, []);
Check::same('коды уникальны', count(array_unique(TypicalPrompts::codes())), count($raw));
Check::same('есть для карточки, списка и общие', array_values(array_unique(array_map(static fn(array $p): string => ChatPlaces::group($p['places']), $raw))), ['card', 'list', 'chat']);

$definition = null;
foreach([getenv('TOOLSAI_DIR') ?: '', dirname($root).'/toolsai', '/home/user/toolsai'] as $dir)
{
	if($dir !== '' && is_file($dir.'/lib/prompt/definition.php'))
	{
		$definition = $dir.'/lib/prompt/definition.php';
		break;
	}
}
if($definition !== null)
{
	require_once $definition;
	$lost = [];
	foreach($raw as $p)
	{
		$d = \Shef\ToolsAi\Prompt\Definition::fromArray($p, Constants::MODULE_ID);
		if($d === null || $d->places !== $p['places'] || $d->type !== $p['type'] || $d->icon !== $p['icon'] || $d->entity !== $p['entity'])
		{
			$lost[] = $p['code'];
		}
	}
	Check::same('настоящий Definition::fromArray() shef.toolsai принимает все запросы целиком (места, вид, иконка, сущность)', $lost, []);
}

Check::group('каталог виджетов');
Check::same('встроенные — те же, что рисует widgets.js', array_keys(WidgetCatalog::BUILTIN), ['kpi', 'table', 'bars', 'record', 'actions']);
$foreign = WidgetCatalog::fromModule(['widgets' => [
	['type' => 'weather', 'extension' => 'my.mod.widgets', 'description' => 'Погода: <b>{"city": "…"}</b>'],
	['type' => 'Bad Type', 'extension' => 'x', 'description' => 'd'],
	['type' => 'noext', 'extension' => '', 'description' => 'd'],
	['type' => 'nodesc', 'extension' => 'x'],
	'мусор',
]], 'my.mod');
Check::same('тип модуля — «модуль:тип», теги из описания вырезаны, мусор прочь', $foreign, [['type' => 'my.mod:weather', 'extension' => 'my.mod.widgets', 'description' => 'Погода: {"city": "…"}']]);
Check::same('негодный владелец — ничего', WidgetCatalog::fromModule(['widgets' => [['type' => 'a', 'extension' => 'b', 'description' => 'c']]], 'Плохой модуль'), []);
$hint = WidgetCatalog::instructions($foreign);
Check::same('подсказка: формат блока, встроенные и модуля', [str_contains($hint, '```widget'), str_contains($hint, '- kpi:'), str_contains($hint, '- my.mod:weather: Погода'), str_contains($hint, 'не выдумывай')], [true, true, true, true]);
Check::same('для окна — только тип и расширение', WidgetCatalog::forClient($foreign), [['type' => 'my.mod:weather', 'extension' => 'my.mod.widgets']]);

Check::finish();
