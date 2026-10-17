<?php

defined('B_PROLOG_INCLUDED') && B_PROLOG_INCLUDED === true || die();

/**
 * Шаблон страницы прав: приложение ui.accessrights.v2 и панель кнопок
 * «Сохранить / Отмена» (bitrix:ui.button.panel) — как
 * documentgenerator.settings.perms.
 *
 * @var array $arResult
 * @global \CMain $APPLICATION
 */

use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;
use Bitrix\UI\Buttons\Button;
use Bitrix\UI\Buttons\Color;
use Bitrix\UI\Buttons\JsCode;

global $APPLICATION;

if($arResult['error'] !== '')
{
	echo '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">'.htmlspecialcharsbx($arResult['error']).'</span></div>';
	return;
}

Extension::load(['ui.accessrights.v2', 'ui.alerts']);
/** @var \Bitrix\UI\AccessRights\V2\Options $options */
$options = $arResult['options'];
?>
<div class="ui-alert ui-alert-primary"><span class="ui-alert-message">
	Роль доступа — набор прав. Назначьте её отделу (вместе с подотделами) или сотруднику; права суммируются по всем ролям человека.
	«Пользоваться чатом» открывает кнопку и панель. Какие роли помощника сотрудник увидит в переключателе чата, задаётся в «Библиотеке промптов» shef.toolsai — поле «Кому доступна» у роли.
	Например, «Контроль руководителя» и «Узкие места» продавцу не нужны: в «Библиотеке промптов» shef.toolsai у этих ролей в «Кому доступна» укажите только руководителей. Там же правятся тексты ролей.
	Администратор портала может всё.
</span></div>
<div id="<?=htmlspecialcharsbx($options->getContainerId())?>"></div>
<script>
	var shefToolsAiAssistantRights = new BX.UI.AccessRights.V2.App(<?=Json::encode($options)?>);
	shefToolsAiAssistantRights.draw();
</script>
<?php
$APPLICATION->IncludeComponent('bitrix:ui.button.panel', '', [
	'HIDE' => true,
	'BUTTONS' => [
		['TYPE' => 'save', 'ONCLICK' => 'shefToolsAiAssistantRights.sendActionRequest()'],
		[
			'TYPE' => 'custom',
			'LAYOUT' => (new Button())
				->setColor(Color::LINK)
				->setText('Отмена')
				->bindEvent('click', new JsCode('shefToolsAiAssistantRights.fireEventReset()'))
				->render(),
		],
	],
]);
