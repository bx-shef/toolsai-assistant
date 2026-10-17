<?php declare(strict_types=1);

/**
 * Обновление без установщика (Main\Maintenance, 1.1.0): на портале модуль
 * обновляют копированием файлов, установщик не запускается. Проверяем: версия
 * совпала — ничего не делаем; отстала — перекопированы только свои каталоги
 * installDir, поставлены все подписки, созданы таблицы и агент, версия
 * записана; сбой шага — версия не пишется (повтор на следующем хите).
 */

namespace
{
	$root = dirname(__DIR__);

	require_once $root.'/tests/stub/autoload.php';
	require_once $root.'/tests/assert.php';

	/** Копирование ядра: запоминаем, что и куда. */
	function CopyDirFiles(string $from, string $to, bool $rewrite = true, bool $recursive = false): bool
	{
		$GLOBALS['copied'][] = [$from, $to, $rewrite, $recursive];

		return true;
	}

	use Bitrix\Main\Config\Option;
	use Bitrix\Main\EventManager;
	use Shef\ToolsAiAssistant\Main\Maintenance;

	$settings = require $root.'/.settings.php';

	Check::group('когда доводить');

	Check::same('версия совпала — нет', Maintenance::isNeeded('1.1.0', '1.1.0'), false);
	Check::same('файлы новее сохранённого — да; не сохраняли — да', [Maintenance::isNeeded('1.0.0', '1.1.0'), Maintenance::isNeeded('', '1.1.0')], [true, true]);
	Check::same('версия модуля — из install/version.php', Maintenance::moduleVersion($root), (function() use ($root) { $arModuleVersion = []; include $root.'/install/version.php'; return $arModuleVersion['VERSION']; })());

	Check::group('что копировать');

	$plan = Maintenance::copyPlan($settings['installDir']['value']);
	Check::same('только свои подкаталоги installDir', $plan, [
		['/install/js/shef/toolsaiassistant', '/bitrix/js/shef/toolsaiassistant'],
		['/install/components/shef/toolsaiassistant.rights', '/local/components/shef/toolsaiassistant.rights'],
		['/install/public/shef/toolsaiassistant', '/shef/toolsaiassistant'],
	]);
	Check::same('чужие пути и «..» — мимо', Maintenance::copyPlan([
		['from' => '/install/js', 'to' => '/bitrix/js', 'customPathUnInstall' => ['/bitrix/js/shef']],
		['from' => '/install/public', 'to' => '', 'customPathUnInstall' => ['/shef/toolsaiassistant/../../bitrix']],
		['from' => '/etc', 'to' => '', 'customPathUnInstall' => ['/shef/toolsaiassistant']],
	]), []);

	Check::group('довести');

	$GLOBALS['copied'] = [];
	EventManager::$registered = [];
	$tables = 0;
	$agents = 0;
	Option::set('shef.toolsaiassistant', 'LEGACY_migrated', 'Y');
	Option::set('shef.toolsaiassistant', 'CHAT_feedbackdays', '30');
	$report = Maintenance::ensure('/srv/portal', $root, static function() use (&$tables): void { $tables++; }, static function() use (&$agents): void { $agents++; });
	Check::same('файлы — свои каталоги, с заменой', array_map(static fn(array $c): array => [str_replace($root, '', $c[0]), $c[1], $c[2]], $GLOBALS['copied']), [
		['/install/js/shef/toolsaiassistant', '/srv/portal/bitrix/js/shef/toolsaiassistant', true],
		['/install/components/shef/toolsaiassistant.rights', '/srv/portal/local/components/shef/toolsaiassistant.rights', true],
		['/install/public/shef/toolsaiassistant', '/srv/portal/shef/toolsaiassistant', true],
	]);
	Check::same('подписки — все из installEvents', count(EventManager::$registered), count($settings['installEvents']['value']));
	Check::same('отметка переноса из shef.toolsai удалена, прочие опции целы', [Option::get('shef.toolsaiassistant', 'LEGACY_migrated', null), Option::get('shef.toolsaiassistant', 'CHAT_feedbackdays')], [null, '30']);
	Check::same('установщик больше не переносит права', str_contains((string)file_get_contents($root.'/install/index.php'), 'LegacyRights'), false);
	Check::same('таблицы и агент', [$tables, $agents], [1, 1]);
	Check::same('всё получилось — версия записана', [in_array(false, $report, true), Option::get('shef.toolsaiassistant', Maintenance::OPTION_VERSION)], [false, Maintenance::moduleVersion($root)]);

	Option::set('shef.toolsaiassistant', Maintenance::OPTION_VERSION, '1.0.0');
	$report = Maintenance::ensure('/srv/portal', $root, static function(): void { throw new \RuntimeException('нет базы'); }, static function(): void {});
	Check::same('сбой таблиц — в отчёте, версия не записана', [$report['tables'], Option::get('shef.toolsaiassistant', Maintenance::OPTION_VERSION)], [false, '1.0.0']);

	Check::group('где запускается');

	Check::same('OnProlog доводит модуль', str_contains((string)file_get_contents($root.'/lib/internals/integration/main/eventhandler/onprologeventhandler.php'), 'Maintenance::ensureOnce()'), true);
	Check::same('установщик пишет версию файлов', str_contains((string)file_get_contents($root.'/install/index.php'), 'Maintenance::OPTION_VERSION'), true);

	Check::finish();
}
