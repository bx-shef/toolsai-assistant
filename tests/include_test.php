<?php declare(strict_types=1);

/**
 * Подключение модуля поднимает зависимости: include.php -> autoload.php ->
 * requireModules из .settings.php. shef.toolsai — жёстко (модель, квота,
 * реестр промптов, история записи); shef.toolsaikb — мягко, на каждом хите
 * не поднимается: его подключает только поиск статей (Chat\Gateway\KbSource).
 *
 * Тест подключает настоящий include.php и смотрит, что поднялось.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Loader;

\Bitrix\Main\Config\Configuration::$settings = require $root.'/.settings.php';

$loaded = [];
foreach(['shef.options', 'shef.problems', 'shef.toolsai', 'shef.toolsaikb', 'crm', 'ui'] as $module)
{
	Loader::$onInclude[$module] = static function() use (&$loaded, $module): void
	{
		$loaded[] = $module;
	};
}

require_once $root.'/include.php';

Check::group('подключение модуля');

Check::same('подняты shef.options, shef.problems, shef.toolsai — по порядку', $loaded, ['shef.options', 'shef.problems', 'shef.toolsai']);
Check::same('shef.toolsaikb, crm и ui на каждом хите не поднимаются', array_intersect($loaded, ['shef.toolsaikb', 'crm', 'ui']), []);
Check::same('чужих namespace не зарегистрировано', Loader::$namespaces, []);

Check::finish();
