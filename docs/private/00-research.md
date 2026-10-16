# Опоры на ядро и shef.toolsai

> Последняя сверка: 2026-10-16 (ядро портала: main 26.700.0)

Что прочитано и на какие строки опирается модуль. Пути ядра — от
`bitrix/modules/`, shef.toolsai — от корня его репозитория.

## Ядро

| что | где | вывод |
|---|---|---|
| ajax.php компонента | `main/lib/engine/router.php:254`, `getAjaxClassForPath()` `:263` | runComponentAction в режиме `ajax` берёт контроллер, объявленный при подключении ajax.php компонента — поэтому компонент прав с ajax.php |
| сохранение страницы прав | `/bitrix/js/ui/accessrights/v2/dist/v2.bundle.js` (около строки 5879) (runComponentAction с `options.component`, `options.actionSave`, `options.mode`) | имя компонента и действие — из `Options` |
| конфиг прав | `ui/lib/AccessRights/V2/Options.php`, `Options/RightSection/RightItem.php:41` | `RightItem(id, title, Type)`; образец — `documentgenerator/install/components/bitrix/documentgenerator.settings.perms/` |
| кнопка в верхней панели | `ui/install/components/bitrix/ui.toolbar/class.php:37` (`addBufferContent`) | тулбар выводится отложенно — кнопку можно добавить в OnProlog |
| расширения | `main/lib/ui/extension.php` (`getPath()`) | `shef.toolsaiassistant.chat` → `/bitrix/js/shef/toolsaiassistant/chat/config.php` |
| таблица по сущности | `main/lib/orm/entity.php:1235` (`createDbTable()`), статья framework-docs `pages/orm/orm-concepts.md` («Создать таблицу») | существующую не меняет; составной уникальный индекс — `createIndex()` (`main/lib/db/mysqlcommonconnection.php:150`, тип `INDEX_UNIQUE`) |
| отпустить сессию | `main/lib/engine/actionfilter/closesession.php:12` | долгий ответ модели не держит другие вкладки |
| фильтры контроллера | framework-docs `pages/framework/controllers.md` | атрибуты есть в main 26.700, но модуль ставится на main от 22.600 — `configureActions()` |
| коды доступа сотрудника | `main/classes/general/access.php:369` (`CAccess::GetUserCodesArray`) | DR<id> всех отделов выше по дереву уже в списке |

## shef.toolsai (main, 1.49.0)

| что | где |
|---|---|
| событие реестра и ответ модулей | `lib/prompt/registryservice.php:67` (`new Event` + `send()`), `defaults()` |
| правила кода промпта | `lib/prompt/definition.php`, `fromArray()` |
| роли и сборка | `lib/prompt/registry.php:60` (`roles()`), `:99` (`compose()`) |
| история записи | `lib/analysis/recordhistory.php:24` |
| модель направления | `lib/container.php:187` (`getLlm()`) |
| квота | `lib/container.php:54` (`getMeter()`), `lib/quota/balance.php` (`isExceeded()`) |
| журнал расхода | `lib/quota/ledger.php:24` (`start()`), `:94` (`finish()`) |
| прежний чат (перенос) | `lib/assistant/sidepanel.php`, `lib/kb/chatstream.php`, `endpoint/kb.php`, `site/app/components/HdChatOverlay.vue` |
| прежние права чата | `lib/access/actions.php` (`assistant.use`, `assistant.panel`), `lib/access/rightspage.php` |
