<?php
$MESS['shef.toolsaiassistant_TAB_PANEL_NAME'] = 'Панель';
$MESS['shef.toolsaiassistant_TAB_PANEL_TITLE'] = 'Чат ИИ-помощника панелью справа';
$MESS['shef.toolsaiassistant_TAB_PANEL_RIGHTS'] = 'Кто пользуется чатом и какие роли помощника кому открыты — на странице <a href="#URL#">«Права доступа»</a>. Тексты ролей — в «Библиотеке промптов» shef.toolsai; модель, квота и расход — в настройках shef.toolsai (направление «Чат ИИ-помощника»). Оценки ответов «полезно / не помогло» — на странице <a href="#FEEDBACK_URL#">«Оценки ответов»</a>.';
$MESS['shef.toolsaiassistant_TAB_PANEL_enabled'] = 'Показывать кнопку «✦ ИИ: чат» и панель';
$MESS['shef.toolsaiassistant_TAB_PANEL_enabled_descr'] = 'Выключено — модуль сам кнопку и панель не добавляет; страницы, которые встраивают чат через Public\Service\ChatPanel, — по-прежнему могут.';
$MESS['shef.toolsaiassistant_TAB_PANEL_paths'] = 'Где показывать чат';
$MESS['shef.toolsaiassistant_TAB_PANEL_paths_descr'] = 'Разделы портала: выберите готовые (CRM, сделки, вид «ИИ» и места других модулей) или наберите свой адрес в поиске и нажмите «Добавить свой адрес» — путь и, при желании, параметры: /shef/toolsai/view/?type=2&category=0. Чат показывается на страницах, путь которых начинается с адреса и где есть все его параметры. Пусто — чат нигде (страницы, которые встраивают чат через Public\\Service\\ChatPanel, — по-прежнему могут).';
$MESS['shef.toolsaiassistant_TAB_CHAT_NAME'] = 'Ответы';
$MESS['shef.toolsaiassistant_TAB_CHAT_TITLE'] = 'Что помощник получает вместе с вопросом';
$MESS['shef.toolsaiassistant_TAB_CHAT_history'] = 'Сообщений переписки в запросе';
$MESS['shef.toolsaiassistant_TAB_CHAT_history_descr'] = 'Последние N сообщений чата уходят модели вместе с вопросом (2–30, по умолчанию 10). Больше — помнит дольше, но каждый ответ дороже.';
$MESS['shef.toolsaiassistant_TAB_CHAT_kblimit'] = 'Статей базы знаний в ответе';
$MESS['shef.toolsaiassistant_TAB_CHAT_kblimit_descr'] = 'Сколько статей shef.toolsaikb подбирать под вопрос (0–10, по умолчанию 5; 0 — не искать). Без модуля shef.toolsaikb не действует.';
$MESS['shef.toolsaiassistant_TAB_CHAT_ratelimit'] = 'Ответов на сотрудника в минуту';
$MESS['shef.toolsaiassistant_TAB_CHAT_ratelimit_descr'] = 'Не больше N вызовов модели одним сотрудником за минуту (0–120, по умолчанию 10; 0 — без ограничения). Защищает общую квоту shef.toolsai от потока запросов.';
$MESS['shef.toolsaiassistant_TAB_CHAT_feedbackdays'] = 'Сколько дней хранить оценки ответов';
$MESS['shef.toolsaiassistant_TAB_CHAT_feedbackdays_descr'] = 'Оценки «полезно / не помогло» старше N дней удаляет агент раз в сутки (1–3650, по умолчанию 90). В оценках — вопрос и ответ помощника, а в них бывают фрагменты записей CRM.';
