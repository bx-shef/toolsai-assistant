<?php declare(strict_types=1);

/**
 * Установщик: подписки на события и раскладка файлов.
 *
 * Здесь ловится то, что на портале всплывает поздно: обработчик, которого
 * нет (событие падает у shef.toolsai на сборке реестра), подписка, которую
 * удаление не сняло, и удаление, которое снесло соседей
 * (/bitrix/js/shef целиком вместо своего подкаталога).
 *
 * Установщик подключается настоящий; CModule, файлы и документ рут — заглушки.
 */

namespace
{
	$root = dirname(__DIR__);

	require_once $root.'/tests/stub/autoload.php';
	require_once $root.'/tests/assert.php';

	class CModule
	{
	}

	$GLOBALS['APPLICATION'] = new class
	{
		public array $exceptions = [];

		public function ThrowException(string $message): void
		{
			$this->exceptions[] = $message;
		}
	};
}

namespace Bitrix\Main
{
	class Application
	{
		public static function getDocumentRoot(): string
		{
			return '/srv/portal';
		}
	}
}

namespace Bitrix\Main\IO
{
	class Directory
	{
		public static array $deleted = [];

		public static function deleteDirectory(string $path): void
		{
			static::$deleted[] = $path;
		}
	}
}

namespace
{
	use Bitrix\Main\EventManager;
	use Bitrix\Main\IO\Directory;

	$root = dirname(__DIR__);
	require $root.'/install/index.php';

	$settings = require $root.'/.settings.php';
	$installer = new shef_toolsaiassistant();

	Check::group('обработчики событий существуют');

	$missing = [];
	foreach($settings['installEvents']['value'] as $event)
	{
		$class = ltrim($event['to']['class'], '\\');
		if(!class_exists($class) || !method_exists($class, $event['to']['function']) || !(new ReflectionMethod($class, $event['to']['function']))->isStatic())
		{
			$missing[] = $class.'::'.$event['to']['function'];
		}
		if($event['to']['module'] !== 'shef.toolsaiassistant')
		{
			$missing[] = 'модуль назначения: '.$event['to']['module'];
		}
	}
	Check::same('каждый обработчик — статический метод существующего класса', $missing, []);
	Check::same(
		'подписки: реестр промптов и вид «ИИ» shef.toolsai, панель',
		array_map(static fn(array $e): string => $e['from']['module'].':'.$e['from']['event'], $settings['installEvents']['value']),
		['shef.toolsai:OnPromptRegistryBuild', 'shef.toolsai:OnViewRowActionsBuild', 'shef.toolsai:OnViewToolbarBuild', 'intranet:onBuildBindingMenu', 'main:OnProlog']
	);

	Check::group('подписка и снятие — парные');

	$installer->InstallEvents();
	$registered = array_map(static fn(array $r): string => implode('|', $r), EventManager::$registered);
	$installer->UnInstallEvents();
	$unregistered = array_map(static fn(array $r): string => implode('|', $r), EventManager::$unregistered);
	Check::same('установка подписала все пять', count($registered), 5);
	Check::same('удаление сняло ровно то, что подписано', array_values(array_diff($registered, $unregistered)), []);

	Check::group('раскладка файлов');

	$bad = [];
	foreach($settings['installDir']['value'] as $map)
	{
		if(!is_dir($root.$map['from']))
		{
			$bad[] = 'нет каталога '.$map['from'];
		}
		if(str_contains($map['from'], '/install/admin'))
		{
			$bad[] = 'install/admin каталогом в /bitrix/admin затрёт файлы ядра';
		}
		foreach($map['customPathUnInstall'] as $path)
		{
			if(!str_contains($path, 'toolsaiassistant'))
			{
				$bad[] = 'удаление снесёт чужое: '.$path;
			}
		}
	}
	Check::same('источники на месте, удаляется только своё', $bad, []);

	$installer->UnInstallFiles();
	Check::same('удаление убирает ровно свои каталоги', Directory::$deleted, [
		'/srv/portal/bitrix/js/shef/toolsaiassistant',
		'/srv/portal/local/components/shef/toolsaiassistant.rights',
		'/srv/portal/shef/toolsaiassistant',
	]);

	Check::group('то, что раскладывается');

	$config = (static function(string $file): array
	{
		define('B_PROLOG_INCLUDED', true);

		return require $file;
	})($root.'/install/js/shef/toolsaiassistant/chat/config.php');
	$files = array_merge((array)$config['js'], (array)$config['css']);
	Check::same('файлы расширения на месте', array_values(array_filter($files, static fn(string $f): bool => !is_file($root.'/install/js/shef/toolsaiassistant/chat/'.$f))), []);
	Check::same('имя расширения — путь в /bitrix/js', \Shef\ToolsAiAssistant\Main\Constants::EXTENSION_CHAT, 'shef.toolsaiassistant.chat');

	$component = $root.'/install/components/shef/toolsaiassistant.rights';
	Check::same('компонент прав: class.php, ajax.php, шаблон', [is_file($component.'/class.php'), is_file($component.'/ajax.php'), is_file($component.'/templates/.default/template.php')], [true, true, true]);
	Check::same('страница прав зовёт свой компонент', str_contains((string)file_get_contents($root.'/install/public/shef/toolsaiassistant/rights/index.php'), "'".\Shef\ToolsAiAssistant\Main\Constants::RIGHTS_COMPONENT."'"), true);
	Check::same('адрес страницы прав — там, куда её кладут', is_file($root.'/install/public'.\Shef\ToolsAiAssistant\Main\Constants::RIGHTS_URL.'index.php'), true);

	Check::group('версии зависимостей');

	Check::same('shef.toolsai — с выпуска, где чат вынесен', $installer->NEED_MODULES_BY_VERSION['shef.toolsai'] ?? null, '1.50.0');
	Check::same('shef.toolsaikb установка не требует', array_key_exists('shef.toolsaikb', $installer->NEED_MODULES_BY_VERSION) || in_array('shef.toolsaikb', $installer->NEED_MODULES, true), false);
	Check::same('страница настроек: права группы', $installer->MODULE_GROUP_RIGHTS, 'Y');

	Check::finish();
}
