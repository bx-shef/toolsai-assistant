<?php

/**
 * Расширение shef.toolsaiassistant.chat — панель справа и окно чата
 * ИИ-помощника. Чистый BX (main.core), без сборки: файлы подключаются как
 * есть. markdown.js — разбор ответа модели в безопасный HTML (проверяется
 * tests/markdown_test.mjs), layout.js — ширина и место панели (1.4.0,
 * tests/layout_test.mjs), status.js — этапы ожидания ответа (1.4.0,
 * tests/status_test.mjs), view.js — вид переписки и roledialog.js — диалог
 * ролей (1.4.0, tests/view_test.mjs, tests/roledialog_test.mjs), stream.js — ответ потоком (1.1.0,
 * tests/stream_test.mjs), chat.js — панель и окно.
 */

if(!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

return [
	'css' => 'chat.css',
	'js' => ['markdown.js', 'stream.js', 'layout.js', 'status.js', 'view.js', 'roledialog.js', 'promptmenu.js', 'widgets.js', 'files.js', 'chat.js'],
	'rel' => ['main.core', 'ui.buttons', 'ui.icon-set.outline', 'ui.icon-set.main', 'ui.design-tokens'],
	'skip_core' => false,
];
