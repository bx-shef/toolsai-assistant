<?php

/**
 * Расширение shef.toolsaiassistant.chat — панель справа и окно чата
 * ИИ-помощника. Чистый BX (main.core), без сборки: файлы подключаются как
 * есть. markdown.js — разбор ответа модели в безопасный HTML (проверяется
 * tests/markdown_test.mjs), chat.js — панель и окно.
 */

if(!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

return [
	'css' => 'chat.css',
	'js' => ['markdown.js', 'chat.js'],
	'rel' => ['main.core', 'ui.buttons'],
	'skip_core' => false,
];
