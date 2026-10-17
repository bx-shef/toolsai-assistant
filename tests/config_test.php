<?php declare(strict_types=1);

/**
 * Настройки: разбор значений из формы, умолчания и страница настроек.
 *
 * Код опции — идентификатор: options_conf.php сохраняет её под
 * <вкладка>_<опция>, Config читает по той же строке. Разошлись — настройка
 * сохраняется, но не действует. Здесь сверяются все три места: страница,
 * default_option.php и Config.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/options.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Config;

Check::group('разбор');

$empty = Config::fromValues([]);
Check::same('пусто — включено', $empty->enabled, true);
Check::same('пусто — CRM и вид «ИИ»', $empty->paths, ['/crm/', '/shef/toolsai/view/']);
Check::same('пусто — история, статьи и частота по умолчанию', [$empty->history, $empty->kbLimit, $empty->rateLimit], [10, 5, 10]);
Check::same('частота: 0 — без ограничения, больше 120 — 120', [Config::fromValues([Config::OPTION_RATE_LIMIT => '0'])->rateLimit, Config::fromValues([Config::OPTION_RATE_LIMIT => '500'])->rateLimit], [0, 120]);

$custom = Config::fromValues([
	Config::OPTION_ENABLED => 'N',
	Config::OPTION_PATHS => "/crm/deal/\r\nhttps://evil.example/\n javascript:x \n/crm/deal/\n/company/",
	Config::OPTION_HISTORY => '99',
	Config::OPTION_KB_LIMIT => '0',
]);
Check::same('N — выключено', $custom->enabled, false);
Check::same('адреса: только пути своего сайта, без повторов', $custom->paths, ['/crm/deal/', '/company/']);
Check::same('история — не больше 30', $custom->history, 30);
Check::same('0 статей — не искать', $custom->kbLimit, 0);
Check::same('мусор в числах — умолчание, а не ноль', [Config::fromValues([Config::OPTION_HISTORY => '5 2'])->history, Config::fromValues([Config::OPTION_KB_LIMIT => '-1'])->kbLimit], [10, 5]);
Check::same('срок оценок: пусто — 90, 0 — 1, мусор — 90', [$empty->feedbackDays, Config::fromValues([Config::OPTION_FEEDBACK_DAYS => '0'])->feedbackDays, Config::fromValues([Config::OPTION_FEEDBACK_DAYS => 'x'])->feedbackDays], [90, 1, 90]);
Check::same('история не меньше 2', Config::fromValues([Config::OPTION_HISTORY => '1'])->history, 2);

Check::group('где показывать');

Check::same('сделки — в CRM', $empty->matchesPath('/crm/deal/list/'), true);
Check::same('вид «ИИ»', $empty->matchesPath('/shef/toolsai/view/'), true);
Check::same('живая лента — нет', $empty->matchesPath('/stream/'), false);
$nowhere = Config::fromValues([Config::OPTION_PATHS => '']);
Check::same('сохранено пустое — чат нигде (1.1.0)', [$nowhere->paths, $nowhere->matchesPath('/crm/deal/list/')], [[], false]);
Check::same('сохранён только мусор — тоже нигде', Config::fromValues([Config::OPTION_PATHS => "https://evil.example/\n"])->paths, []);

Check::group('умолчания и страница настроек');

$shef_toolsaiassistant_default_option = [];
require $root.'/default_option.php';
Check::group('агент (1.9.0)');
Check::same('пусто — агент выключен, адреса нет, 6 шагов, чтение без вопроса, 8000 знаков', [$empty->agentEnabled, $empty->agentUrl, $empty->agentSteps, $empty->agentAutoRead, $empty->agentResultMax, $empty->agentReady()], [false, '', 6, true, 8000, false]);
$agent = Config::fromValues([Config::OPTION_AGENT_ENABLED => 'Y', Config::OPTION_AGENT_URL => 'https://Box.Example.by/mcp-ai/mcp/', Config::OPTION_AGENT_STEPS => '99', Config::OPTION_AGENT_AUTOREAD => 'N', Config::OPTION_AGENT_RESULT => '10']);
Check::same('адрес — база без /mcp, хост строчными; шаги не больше 15; результат не меньше 1000', [$agent->agentUrl, $agent->agentSteps, $agent->agentAutoRead, $agent->agentResultMax, $agent->agentReady()], ['https://box.example.by/mcp-ai', 15, false, 1000, true]);
Check::same('адрес: не https, с логином, с параметрами, с кавычкой — нет', array_map(static fn($u) => Config::mcpUrl($u), ['http://box.by/mcp', 'https://u:p@box.by', 'https://box.by/mcp?x=1', 'https://box.by/"x', 'javascript:alert(1)']), [null, null, null, null, null]);
Check::same('/connect в конце — тоже убирается, порт остаётся', Config::mcpUrl('https://box.by:8443/connect'), 'https://box.by:8443');
Check::same('включён без адреса — агента нет', Config::fromValues([Config::OPTION_AGENT_ENABLED => 'Y', Config::OPTION_AGENT_URL => 'http://x'])->agentReady(), false);

$defaults = Config::fromValues($shef_toolsaiassistant_default_option);
Check::same('default_option.php даёт те же умолчания, что Config', [$defaults->enabled, $defaults->paths, $defaults->history, $defaults->kbLimit, $defaults->rateLimit, $defaults->agentEnabled, $defaults->agentUrl, $defaults->agentSteps, $defaults->agentAutoRead, $defaults->agentResultMax], [$empty->enabled, $empty->paths, $empty->history, $empty->kbLimit, $empty->rateLimit, $empty->agentEnabled, $empty->agentUrl, $empty->agentSteps, $empty->agentAutoRead, $empty->agentResultMax]);

$tabs = require $root.'/options_conf.php';
$codes = [];
foreach($tabs as $tab)
{
	foreach($tab->getOptionList() as $option)
	{
		if(!$option instanceof \Shef\Options\Main\Options\RowInfo)
		{
			$codes[] = $tab->getCode().'_'.$option->getCode();
		}
	}
}
sort($codes);
$expected = [Config::OPTION_ENABLED, Config::OPTION_PATHS, Config::OPTION_HISTORY, Config::OPTION_KB_LIMIT, Config::OPTION_RATE_LIMIT, Config::OPTION_FEEDBACK_DAYS,
	Config::OPTION_AGENT_ENABLED, Config::OPTION_AGENT_URL, Config::OPTION_AGENT_STEPS, Config::OPTION_AGENT_AUTOREAD, Config::OPTION_AGENT_RESULT];
sort($expected);
$pathsField = null;
foreach($tabs as $tab) { foreach($tab->getOptionList() as $option) { if($tab->getCode().'_'.$option->getCode() === Config::OPTION_PATHS) { $pathsField = $option; } } }
Check::same('поле «Где показывать чат» — подбор мест (ui.entity-selector, 1.3.0)', $pathsField instanceof \Shef\ToolsAiAssistant\Panel\PlacesOption, true);
Check::same('страница настроек сохраняет ровно то, что читает Config', $codes, $expected);
$defaultKeys = array_keys($shef_toolsaiassistant_default_option);
sort($defaultKeys);
Check::same('у каждой опции есть умолчание', $defaultKeys, $expected);

\Bitrix\Main\Localization\Loc::loadLangFile($root.'/lang/ru/options.php');
$missing = [];
foreach(['PANEL', 'CHAT', 'AGENT'] as $tab)
{
	foreach(['NAME', 'TITLE'] as $key)
	{
		if(\Bitrix\Main\Localization\Loc::getMessage('shef.toolsaiassistant_TAB_'.$tab.'_'.$key) === null)
		{
			$missing[] = $tab.'_'.$key;
		}
	}
}
foreach($expected as $code)
{
	if(\Bitrix\Main\Localization\Loc::getMessage('shef.toolsaiassistant_TAB_'.$code) === null)
	{
		$missing[] = $code;
	}
}
Check::same('подписи есть у вкладок и опций', $missing, []);

Check::finish();
