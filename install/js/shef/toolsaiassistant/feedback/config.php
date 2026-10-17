<?php

/**
 * Расширение shef.toolsaiassistant.feedback (1.5.0) — страница «Оценки
 * ответов»: окно оценки (вопрос, ответ, комментарий целиком, «что должно было
 * быть»), групповые действия грида, выгрузка кейсов проверки файлом. Ответ
 * модели размечает тот же markdown.js, что окно чата (экранирует весь текст,
 * ссылки — только «/…» и http(s)); путь — абсолютный, файл не копируем.
 * Чистый BX (main.core, main.popup), без сборки. Тест — tests/feedback_test.mjs.
 */

if(!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

return [
	'css' => 'feedback.css',
	'js' => ['/bitrix/js/shef/toolsaiassistant/chat/markdown.js', 'feedback.js'],
	'rel' => ['main.core', 'main.popup', 'ui.buttons', 'ui.notification'],
	'skip_core' => false,
];
