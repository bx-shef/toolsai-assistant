<?php

/**
 * ajax.php компонента shef:toolsaiassistant.rights.
 *
 * Страница прав (ui.accessrights.v2) сохраняет через
 * BX.ajax.runComponentAction(…, {mode: 'ajax'}): ядро подключает ajax.php из
 * каталога компонента и берёт контроллер, объявленный ИМЕННО при его
 * подключении (main/lib/engine/router.php, getAjaxClassForPath). Поэтому
 * здесь объявлен свой наследник Controller\Access модуля.
 */

defined('B_PROLOG_INCLUDED') && B_PROLOG_INCLUDED === true || die();

if(\Bitrix\Main\Loader::includeModule('shef.toolsaiassistant') && !class_exists('ShefToolsAiAssistantRightsAjaxController', false))
{
	final class ShefToolsAiAssistantRightsAjaxController extends \Shef\ToolsAiAssistant\Controller\Access
	{
	}
}
