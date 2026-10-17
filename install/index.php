<?php

use Bitrix\Main\Application;
use Bitrix\Main\Config;
use Bitrix\Main\Error;
use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;

if(class_exists('shef_toolsaiassistant'))
{
	return;
}

Loc::loadMessages(__FILE__);

/**
 * Установка shef.toolsaiassistant — чат ИИ-помощника с ролями.
 *
 * Канон — установщики shef.options и shef.toolsai: проверка UTF-8, версии
 * PHP, главного модуля и модулей линейки; прервать установку можно только
 * ShowForm('ERROR') (он заканчивается die()).
 *
 *   1) таблицы: роли доступа (три) и выбранная роль на сотрудника и чат;
 *      заготовки ролей доступа «Продавец» и «Руководитель продаж» — без
 *      участников, если ролей ещё нет;
 *   2) события из installEvents (.settings.php): роли помощника в реестр
 *      промптов shef.toolsai и панель на страницах CRM;
 *   3) файлы из installDir: JS-расширение в /bitrix/js/shef/toolsaiassistant,
 *      компонент прав в /local/components/shef/toolsaiassistant.rights,
 *      страница /shef/toolsaiassistant/rights/. Удаление убирает ровно их.
 */
class shef_toolsaiassistant extends CModule
{
	public $MODULE_ID = 'shef.toolsaiassistant';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $MODULE_SORT;
	/** Страница настроек (options.php shef.options) проверяет GetGroupRight($mid). */
	public $MODULE_GROUP_RIGHTS = 'Y';

	public $PARTNER_NAME;
	public $PARTNER_URI;

	/** @var string */
	public $PHP_MIN_VER = '8.2.0';
	/** @var string */
	public $NEED_MAIN_VERSION = '22.600.300';
	/**
	 * crm — история записи и права на неё, ui — страница прав
	 * (ui.accessrights.v2) и кнопка в верхней панели. В requireModules их
	 * нет: autoload.php поднимал бы их на каждой загрузке модуля.
	 *
	 * @var array
	 */
	public $NEED_MODULES = [
		'crm',
		'ui',
	];
	/**
	 * shef.toolsai 1.50.0 — выпуск, где чат вынесен в этот модуль (до него у
	 * shef.toolsai своя панель с тем же window.ShefAiChat). Реестр промптов —
	 * с 1.48.0, история записи — с 1.45.0.
	 *
	 * @var array
	 */
	public $NEED_MODULES_BY_VERSION = [
		'shef.options' => '3.0.0',
		'shef.problems' => '2.0.0',
		'shef.toolsai' => '1.50.0',
	];

	/** @var \CMain */
	private $application;

	/** Версия, название и партнёр модуля — из version.php и языкового файла. */
	public function __construct()
	{
		$arModuleVersion = [];

		require __DIR__.'/version.php';

		$this->MODULE_VERSION = $arModuleVersion['VERSION'];
		$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];

		$this->MODULE_NAME = Loc::getMessage('shef.toolsaiassistant_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('shef.toolsaiassistant_MODULE_DESC');

		$sort = 'MODULE_SORT';
		$this->{$sort} = (int)'1006';

		$this->PARTNER_NAME = Loc::getMessage('shef.toolsaiassistant_PARTNER_NAME');
		$this->PARTNER_URI = Loc::getMessage('shef.toolsaiassistant_PARTNER_URI');

		$this->application = $GLOBALS['APPLICATION'];
	}

	// region DB && Register Module ////
	/**
	 * Регистрация модуля, таблицы, заготовки ролей доступа.
	 *
	 * @memo Need call first
	 */
	public function InstallDB(array $arParams = []): bool
	{
		RegisterModule($this->MODULE_ID);

		\Bitrix\Main\Loader::includeModule($this->MODULE_ID);

		\Shef\ToolsAiAssistant\Access\RoleStore::install();
		\Shef\ToolsAiAssistant\Chat\RoleChoiceStore::install();
		\Shef\ToolsAiAssistant\Feedback\FeedbackStore::install();
		\Shef\ToolsAiAssistant\Access\RoleStore::seed();
		// Права чата shef.toolsai до 1.50.0 (assistant.use / assistant.panel) — в своё право
		\Shef\ToolsAiAssistant\Access\LegacyRights::migrate();

		return true;
	}

	/**
	 * Снятие регистрации.
	 *
	 * @param array $arParams ключ savedata = 'Y' оставляет настройки и таблицы
	 * @memo Need call last
	 */
	public function UnInstallDB(array $arParams = []): bool
	{
		$isSaveData = ($arParams['savedata'] ?? 'N') === 'Y';

		if(!$isSaveData)
		{
			try
			{
				\Bitrix\Main\Loader::includeModule($this->MODULE_ID);
				\Shef\ToolsAiAssistant\Chat\RoleChoiceStore::uninstall();
				\Shef\ToolsAiAssistant\Feedback\FeedbackStore::uninstall();
				\Shef\ToolsAiAssistant\Access\RoleStore::uninstall();
			}
			catch(\Throwable)
			{
			}
			Config\Option::delete($this->MODULE_ID);
		}

		UnRegisterModule($this->MODULE_ID);

		$GLOBALS['CACHE_MANAGER']->CleanAll();

		return true;
	}
	// endregion ////

	// region Events ////
	/** Постоянные подписки из installEvents (.settings.php). */
	public function InstallEvents(): bool
	{
		$manager = EventManager::getInstance();
		foreach($this->getEventList() as $event)
		{
			$method = !empty($event['isCompatible']) ? 'registerEventHandlerCompatible' : 'registerEventHandler';
			$manager->{$method}(
				$event['from']['module'],
				$event['from']['event'],
				$event['to']['module'],
				$event['to']['class'],
				$event['to']['function'],
				(int)($event['to']['sort'] ?? 100)
			);
		}

		// Реестр промптов shef.toolsai мог уже собраться в этом хите — перечитать с нашими ролями
		if(\Bitrix\Main\Loader::includeModule('shef.toolsai') && class_exists(\Shef\ToolsAi\Public\Service\PromptService::class))
		{
			\Shef\ToolsAi\Public\Service\PromptService::forget();
		}

		return true;
	}

	/** Снять те же подписки — с тем же назначением (class, method). */
	public function UnInstallEvents(): bool
	{
		$manager = EventManager::getInstance();
		foreach($this->getEventList() as $event)
		{
			foreach([$event['to']['class'], ltrim($event['to']['class'], '\\')] as $class)
			{
				$manager->unRegisterEventHandler(
					$event['from']['module'],
					$event['from']['event'],
					$event['to']['module'],
					$class,
					$event['to']['function']
				);
			}
		}

		return true;
	}

	private function getEventList(): array
	{
		$list = $this->getSettings()['installEvents'] ?? [];

		return is_array($list) ? $list : [];
	}
	// endregion ////

	// region Files ////
	/** Каталог модуля — там, где он стоит: /bitrix/modules или /local/modules. */
	private function getModuleDir(): string
	{
		return dirname(__DIR__);
	}

	/**
	 * Настройки модуля прямо из файла: при установке модуль ещё не
	 * зарегистрирован, и Configuration::getInstance($id) их не знает.
	 */
	private function getSettings(): array
	{
		$settings = include $this->getModuleDir().'/.settings.php';
		$out = [];
		foreach(is_array($settings) ? $settings : [] as $key => $item)
		{
			$out[$key] = is_array($item) ? ($item['value'] ?? null) : null;
		}

		return $out;
	}

	private function getDirList(): array
	{
		$list = $this->getSettings()['installDir'] ?? [];

		return is_array($list) ? $list : [];
	}

	/** Разложить файлы по installDir из .settings.php: JS-расширение, компонент прав, страницу прав. */
	public function InstallFiles(array $arParams = []): bool
	{
		$docRoot = (string)Application::getDocumentRoot();
		foreach($this->getDirList() as $map)
		{
			\CopyDirFiles($this->getModuleDir().$map['from'], $docRoot.$map['to'], true, true);
		}

		return true;
	}

	/**
	 * Удалить ровно то, что раскладывали: customPathUnInstall каждого пункта
	 * installDir. Соседей (/bitrix/js/shef/<другой модуль>, чужие компоненты
	 * в /local/components/shef/) не трогаем.
	 */
	public function UnInstallFiles(): bool
	{
		$response = $this->checkChildModules();
		if(!$response->isSuccess())
		{
			$this->application->ThrowException(
				implode(PHP_EOL.'<br>', array_merge([Loc::getMessage('SH_PROBLEM_UNINSTALL_MODULE')], $response->getErrorMessages()))
			);

			return false;
		}

		$docRoot = (string)Application::getDocumentRoot();
		foreach($this->getDirList() as $map)
		{
			if(($map['isNeedUnInstall'] ?? true) === false)
			{
				continue;
			}
			foreach((array)($map['customPathUnInstall'] ?? []) as $path)
			{
				if(is_string($path) && preg_match('~^/[\w\-./]+$~', $path) && !str_contains($path, '..'))
				{
					\Bitrix\Main\IO\Directory::deleteDirectory($docRoot.$path);
				}
			}
		}

		return true;
	}
	// endregion ////

	// region Install.Public ////
	/** Установка из «Настройки → Модули»: проверки окружения, таблицы, события, файлы. */
	public function DoInstall(): void
	{
		/**
		 * Модуль поставляется в UTF-8 и перекодировкой при установке не
		 * занимается: на проекте в CP1251 русский текст стал бы мусором молча.
		 */
		if(!Application::isUtfMode())
		{
			$this->ShowForm('ERROR', Loc::getMessage('SH_NEED_UTF8'));
		}

		$phpVer = phpversion();
		if(version_compare($phpVer, $this->PHP_MIN_VER, '<'))
		{
			$this->ShowForm('ERROR', Loc::getMessage('SH_NEED_PHP_VER', ['#CURRENT#' => $phpVer, '#NEED#' => $this->PHP_MIN_VER]));
		}

		foreach($this->NEED_MODULES as $module)
		{
			if(!ModuleManager::isModuleInstalled($module))
			{
				$this->ShowForm('ERROR', Loc::getMessage('SH_NEED_MODULES', ['#NEED#' => $module]));
			}
		}

		foreach($this->NEED_MODULES_BY_VERSION as $module => $ver)
		{
			if(
				!ModuleManager::isModuleInstalled($module)
				|| version_compare((string)ModuleManager::getVersion($module), $ver) < 0
			)
			{
				$this->ShowForm('ERROR', Loc::getMessage('SH_NEED_MODULES_BY_VERSION', [
					'#URL#' => 'https://github.com/bx-shef/'.str_replace('shef.', '', $module),
					'#NEED#' => $module,
					'#VER#' => $ver,
				]));
			}
		}

		if(mb_strlen($this->NEED_MAIN_VERSION) > 0 && version_compare(SM_VERSION, $this->NEED_MAIN_VERSION) < 0)
		{
			$this->ShowForm('ERROR', Loc::getMessage('SH_NEED_RIGHT_VER', ['#NEED#' => $this->NEED_MAIN_VERSION]));
		}

		$this->InstallDB();
		$this->InstallEvents();
		$this->InstallFiles();

		$this->ShowForm('OK', Loc::getMessage('SH_MOD_INST_OK').'<br><br>'.Loc::getMessage('SH_TOOLSAIASSISTANT_SETUP_HINT'));
	}

	/** Удаление: файлы (если нет зависимых модулей), события, таблицы и настройки. */
	public function DoUninstall(): void
	{
		if(!$this->UnInstallFiles())
		{
			$problem = $this->application->GetException();
			$this->ShowForm('ERROR', $problem instanceof \CApplicationException ? $problem->GetString() : 'Error Uninstall');
		}
		$this->UnInstallEvents();
		$this->UnInstallDB();
	}

	private function ShowForm(string $type, string $message, string $buttonName = ''): void
	{
		/** @memo need for show title at prolog */
		$keys = array_keys($GLOBALS);
		for($i = 0; $i < count($keys); $i++)
		{
			if($keys[$i] != 'i' && $keys[$i] != 'GLOBALS' && $keys[$i] != 'strTitle' && $keys[$i] != 'filepath')
			{
				global ${$keys[$i]};
			}
		}
		global $APPLICATION, $adminPage, $adminMenu, $adminChain, $USER;

		$this->application->SetTitle(Loc::getMessage('shef.toolsaiassistant_MODULE_NAME'));

		include(Application::getDocumentRoot().'/bitrix/modules/main/include/prolog_admin_after.php');

		\CAdminMessage::ShowMessage([
			'MESSAGE' => $message,
			'TYPE' => $type,
			'HTML' => true,
		]);
		?>
		<form action="<?=$this->application->GetCurPage()?>" method="get">
			<p>
				<input type="hidden" name="lang" value="<?=LANG?>">
				<input type="submit" value="<?=($buttonName <> '' ? $buttonName : Loc::getMessage('SH_MOD_BACK'))?>">
			</p>
		</form>
		<?php
		include(Application::getDocumentRoot().'/bitrix/modules/main/include/epilog_admin.php');
		die();
	}
	// endregion ////

	// region Tools ////
	/** Модули, которые требуют этот (requireModules), — удалить их раньше. */
	private function checkChildModules(): Result
	{
		$result = new Result();
		foreach(ModuleManager::getInstalledModules() as $moduleId => $moduleDesc)
		{
			if($this->MODULE_ID === $moduleId)
			{
				continue;
			}
			$requireModules = Config\Configuration::getInstance($moduleId)->get('requireModules');
			if(is_array($requireModules) && in_array($this->MODULE_ID, $requireModules, true))
			{
				$result->addError(new Error(Loc::getMessage('SH_NEED_UNINSTALL_MODULE_BEFORE', ['#TARGET#' => $moduleId])));
			}
		}

		return $result;
	}
	// endregion /////
}
