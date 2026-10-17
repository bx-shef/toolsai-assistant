<?php

/**
 * Страница «Оценки ответов» модуля shef.toolsaiassistant (1.1.0): кто и в
 * какой роли отметил ответ помощника «полезно» или «не помогло». Право —
 * «Смотреть оценки ответов» (администратору — всегда). Ссылка — на странице
 * настроек модуля. Копируется установщиком (installDir), удаляется при
 * удалении модуля.
 */

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php';

/** @global \CMain $APPLICATION */
$APPLICATION->SetTitle('[SH] ИИ чат с ролями: оценки ответов');
if(\Bitrix\Main\Loader::includeModule('shef.toolsaiassistant'))
{
	\Bitrix\Main\UI\Extension::load(['ui.alerts']);
	\Shef\ToolsAiAssistant\Feedback\FeedbackPage::show();
}

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php';
