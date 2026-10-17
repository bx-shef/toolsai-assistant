<?php declare(strict_types=1);

/**
 * Места «Где показывать чат» (1.3.0): адрес места и сопоставление со
 * страницей (Panel\PlaceUrl), разбор ответов события OnChatPlacesCollect и
 * слияние списков (Panel\PlaceCatalog), регистрация провайдера
 * ui.entity-selector в .settings.php.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Panel\PlaceCatalog;
use Shef\ToolsAiAssistant\Panel\PlaceUrl;

Check::group('адрес места');

Check::same('путь как есть', PlaceUrl::normalize(' /crm/ '), '/crm/');
Check::same('путь с параметрами', PlaceUrl::normalize('/shef/toolsai/view/?type=2&category=0'), '/shef/toolsai/view/?type=2&category=0');
Check::same('пустой «?» отбрасывается', PlaceUrl::normalize('/crm/deal/?'), '/crm/deal/');
Check::same('чужой адрес, //домен, javascript:, пробел, # — нет', [
	PlaceUrl::normalize('https://evil.example/'),
	PlaceUrl::normalize('//evil.example/'),
	PlaceUrl::normalize('javascript:x'),
	PlaceUrl::normalize('/crm/ deal/'),
	PlaceUrl::normalize('/crm/#x'),
	PlaceUrl::normalize('/crm/"><script>'),
], [null, null, null, null, null, null]);

Check::group('страница и место');

Check::same('начало пути', PlaceUrl::matches('/crm/', '/crm/deal/list/?x=1'), true);
Check::same('другой раздел — нет', PlaceUrl::matches('/crm/', '/stream/'), false);
$view = '/shef/toolsai/view/?type=2&category=0';
Check::same('все параметры места есть — да', PlaceUrl::matches($view, '/shef/toolsai/view/?category=0&type=2&page=3'), true);
Check::same('другое значение — нет', PlaceUrl::matches($view, '/shef/toolsai/view/?type=2&category=1'), false);
Check::same('нет параметра — нет', PlaceUrl::matches($view, '/shef/toolsai/view/?type=2'), false);
Check::same('без параметров у страницы — нет', PlaceUrl::matches($view, '/shef/toolsai/view/'), false);
Check::same('index.php под путём — да', PlaceUrl::matches('/shef/toolsai/view/?type=2', '/shef/toolsai/view/index.php?type=2'), true);
Check::same('кодировка в запросе страницы', PlaceUrl::matches('/x/?q=a b', '/x/?q=a%20b'), false);
Check::same('кодировка: %20 в месте и на странице', PlaceUrl::matches('/x/?q=a%20b', '/x/?q=a+b'), true);
Check::same('#якорь страницы не мешает', PlaceUrl::matches('/crm/?a=1', '/crm/?a=1#top'), true);
Check::same('негодное место — ни к чему', PlaceUrl::matches('https://x/', '/crm/'), false);

Check::group('Config: адреса с параметрами и совместимость');

$config = Config::fromValues([Config::OPTION_PATHS => "/crm/deal/\n/shef/toolsai/view/?type=2&category=0\nhttps://evil/"]);
Check::same('сохранённые адреса — как были, параметры тоже', $config->paths, ['/crm/deal/', '/shef/toolsai/view/?type=2&category=0']);
Check::same('панель — по пути и параметрам', [
	$config->matchesPath('/crm/deal/details/1/'),
	$config->matchesPath('/shef/toolsai/view/?type=2&category=0'),
	$config->matchesPath('/shef/toolsai/view/?type=1'),
], [true, true, false]);
Check::same('пусто — нигде; нет настройки — CRM и вид «ИИ»', [Config::fromValues([Config::OPTION_PATHS => ''])->paths, Config::fromValues([])->paths], [[], ['/crm/', '/shef/toolsai/view/']]);

Check::group('готовые места');

$builtin = PlaceCatalog::builtin();
Check::same('свои: вся CRM первой', $builtin[0], ['id' => 'crm', 'title' => 'Вся CRM', 'url' => '/crm/', 'group' => 'CRM']);
Check::same('свои: сделки, лиды, контакты, компании', array_column(array_slice($builtin, 1, 4), 'url'), ['/crm/deal/', '/crm/lead/', '/crm/contact/', '/crm/company/']);
Check::same('все свои адреса проходят разбор', count(array_filter(array_column($builtin, 'url'), static fn($u) => PlaceUrl::normalize($u) === $u)), count($builtin));

$raw = [
	['id' => 'view', 'title' => 'Вид «ИИ»', 'url' => '/shef/toolsai/view/', 'group' => 'ИИ'],
	['id' => 'settings', 'title' => 'Настройки ИИ', 'url' => '/shef/toolsai/'],
	['id' => '', 'title' => 'без id', 'url' => '/a/'],
	['id' => 'x', 'title' => '', 'url' => '/b/'],
	['id' => 'y', 'title' => 'Чужой', 'url' => 'https://evil/'],
	'мусор',
];
$places = PlaceCatalog::normalize($raw, 'shef.toolsai');
Check::same('негодные места пропущены', array_column($places, 'id'), ['view', 'settings']);
Check::same('без группы — ID модуля', $places[1]['group'], 'shef.toolsai');
Check::same('не массив — пусто', PlaceCatalog::normalize('x', 'm'), []);

$merged = PlaceCatalog::merge($builtin, $places, [['id' => 'dup', 'title' => 'Повтор CRM', 'url' => '/crm/', 'group' => 'g']]);
Check::same('повтор адреса — берётся первое (своё)', [count($merged), PlaceCatalog::findByUrl($merged, '/crm/')['title']], [count($builtin) + 2, 'Вся CRM']);
Check::same('сохранённый адрес места — узнаётся, свой — нет', [PlaceCatalog::findByUrl($merged, '/shef/toolsai/view/')['title'] ?? null, PlaceCatalog::findByUrl($merged, '/x/')], ['Вид «ИИ»', null]);

Check::group('провайдер ui.entity-selector');

$settings = require $root.'/.settings.php';
$entities = $settings['ui.entity-selector']['value']['entities'] ?? [];
Check::same('сущность shef-assistant-place, класс провайдера', [$entities[0]['entityId'] ?? null, ltrim($entities[0]['provider']['className'] ?? '', '\\'), $entities[0]['provider']['moduleId'] ?? null], [
	'shef-assistant-place',
	'Shef\\ToolsAiAssistant\\Internals\\Integration\\Ui\\EntitySelector\\PlaceProvider',
	'shef.toolsaiassistant',
]);
$providerSource = (string)file_get_contents($root.'/lib/internals/integration/ui/entityselector/placeprovider.php');
Check::same('файл провайдера там, где его ищет автозагрузка, и та же сущность', str_contains($providerSource, "ENTITY_ID = 'shef-assistant-place'"), true);
Check::same('событие: модуль и имя', [\Shef\ToolsAiAssistant\Main\Constants::MODULE_ID, \Shef\ToolsAiAssistant\Public\Event\OnChatPlacesCollectEvent::TYPE], ['shef.toolsaiassistant', 'OnChatPlacesCollect']);

Check::group('поле настроек');

require_once $root.'/tests/stub/options.php';
use Shef\ToolsAiAssistant\Panel\PlacesOption;

$urls = PlacesOption::savedUrls("/crm/\r\nhttps://evil/\n/shef/toolsai/view/?type=2&category=0\n/crm/\n javascript:x");
Check::same('сохранённое: мусор и повторы отброшены', $urls, ['/crm/', '/shef/toolsai/view/?type=2&category=0']);
Check::same('скрытое поле — формат PANEL_paths (строки)', PlacesOption::hiddenValue($urls), "/crm/\n/shef/toolsai/view/?type=2&category=0");
Check::same('скрытое поле читается Config обратно так же', Config::fromValues([Config::OPTION_PATHS => PlacesOption::hiddenValue($urls)])->paths, $urls);
Check::same('пусто — пусто (чат нигде)', PlacesOption::hiddenValue(PlacesOption::savedUrls('')), '');

Check::group('шаблон адреса: PHP и JS — одно');

$cases = ['/crm/', '/shef/toolsai/view/?type=2&category=0', '/раздел/', '/x/?q=a%20b', 'https://evil/', '//evil/', '/a b/', '/a/#x', '/a,b/', 'javascript:x', '/a/?x="1"'];
$php = array_map(static fn(string $u): bool => (bool)preg_match('#'.PlaceUrl::PATTERN_BODY.'#u', $u), $cases);
Check::same('PHP: свои пути да, чужое нет', $php, [true, true, true, true, false, false, false, false, false, false, false]);
$node = trim((string)shell_exec('command -v node'));
if($node !== '')
{
	$js = (string)shell_exec(escapeshellarg($node).' -e '.escapeshellarg('const re=new RegExp(process.argv[1],"u");console.log(JSON.stringify(JSON.parse(process.argv[2]).map(u=>re.test(u))))').' '.escapeshellarg(PlaceUrl::PATTERN_BODY).' '.escapeshellarg(json_encode($cases)));
	Check::same('JS (new RegExp(..., "u")) решает так же', json_decode(trim($js), true), $php);
}

Check::finish();
