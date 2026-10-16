<?php

/**
 * Страница «Права доступа» модуля shef.toolsaiassistant. Ссылка — на
 * странице настроек модуля (/bitrix/admin/settings.php?mid=shef.toolsaiassistant).
 * Копируется установщиком (installDir), удаляется при удалении модуля.
 */

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php';

/** @global \CMain $APPLICATION */
$APPLICATION->SetTitle('ИИ-помощник: права доступа');
$APPLICATION->IncludeComponent('shef:toolsaiassistant.rights', '', []);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php';
