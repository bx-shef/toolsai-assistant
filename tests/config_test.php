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
Check::same('история не меньше 2', Config::fromValues([Config::OPTION_HISTORY => '1'])->history, 2);

Check::group('где показывать');

Check::same('сделки — в CRM', $empty->matchesPath('/crm/deal/list/'), true);
Check::same('вид «ИИ»', $empty->matchesPath('/shef/toolsai/view/'), true);
Check::same('живая лента — нет', $empty->matchesPath('/stream/'), false);

Check::group('умолчания и страница настроек');

$shef_toolsaiassistant_default_option = [];
require $root.'/default_option.php';
$defaults = Config::fromValues($shef_toolsaiassistant_default_option);
Check::same('default_option.php даёт те же умолчания, что Config', [$defaults->enabled, $defaults->paths, $defaults->history, $defaults->kbLimit, $defaults->rateLimit], [$empty->enabled, $empty->paths, $empty->history, $empty->kbLimit, $empty->rateLimit]);

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
$expected = [Config::OPTION_ENABLED, Config::OPTION_PATHS, Config::OPTION_HISTORY, Config::OPTION_KB_LIMIT, Config::OPTION_RATE_LIMIT];
sort($expected);
Check::same('страница настроек сохраняет ровно то, что читает Config', $codes, $expected);
$defaultKeys = array_keys($shef_toolsaiassistant_default_option);
sort($defaultKeys);
Check::same('у каждой опции есть умолчание', $defaultKeys, $expected);

\Bitrix\Main\Localization\Loc::loadLangFile($root.'/lang/ru/options.php');
$missing = [];
foreach(['PANEL', 'CHAT'] as $tab)
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
