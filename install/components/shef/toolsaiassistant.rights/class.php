<?php

defined('B_PROLOG_INCLUDED') && B_PROLOG_INCLUDED === true || die();

use Bitrix\Main\Loader;
use Shef\ToolsAiAssistant\Access\RightsPage;
use Shef\ToolsAiAssistant\Container;

/**
 * Компонент страницы «Права доступа» shef.toolsaiassistant.
 *
 * Страница на штатном ui.accessrights.v2 — как
 * documentgenerator.settings.perms (bitrix/modules/documentgenerator/install/
 * components/bitrix/documentgenerator.settings.perms/class.php). Конфиг —
 * Access\RightsPage::options(); роли помощника (1.6.0) открывает библиотека
 * промптов shef.toolsai, не эта страница. Только администратору портала.
 *
 * Установщик копирует компонент в /local/components/shef/ (installDir в
 * .settings.php), удаление убирает его оттуда.
 */
class ShefToolsAiAssistantRightsComponent extends CBitrixComponent
{
	/** Проверить права и модули, собрать конфиг страницы прав и вывести шаблон. */
	public function executeComponent(): void
	{
		global $USER;

		$this->arResult['error'] = '';
		if(!$USER instanceof \CUser || !$USER->IsAdmin())
		{
			$this->arResult['error'] = 'Права доступа к чату ИИ-помощника настраивает только администратор портала.';
		}
		elseif(!Loader::includeModule('shef.toolsaiassistant') || !Loader::includeModule('ui') || !class_exists(\Bitrix\UI\AccessRights\V2\Options::class))
		{
			$this->arResult['error'] = 'Для страницы прав нужны модули shef.toolsaiassistant и ui с ui.accessrights.v2 — обновите Битрикс24.';
		}
		else
		{
			try
			{
				$this->arResult['options'] = RightsPage::options();
			}
			catch(\Throwable $throwable)
			{
				Container::getLogger(self::class)?->error($throwable);
				$this->arResult['error'] = 'Не удалось прочитать роли доступа — подробности в журнале модуля.';
			}
		}

		$this->includeComponentTemplate();
	}
}
