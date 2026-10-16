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

## shef.toolsai (ветка claude/split-modules, 1.50.0, PR bx-shef/toolsai#211)

| что | где |
|---|---|
| публичный API, обещание совместимости | `docs/guide/32-public-api.md` |
| вызов модели с квотой и журналом | `lib/public/service/llmservice.php` (`complete()`, `model()`, `ERROR_*`), `lib/public/service/llmresponse.php` |
| реестр промптов | `lib/public/service/promptservice.php` (`roles()`, `compose()`, `PLACE_ASSISTANT`, `forget()`) |
| история записи | `lib/public/service/recordservice.php` (`get()`) |
| действия строки вида «ИИ» | `lib/public/event/viewrowactionsbuildevent.php` |
| событие реестра | `lib/prompt/registryservice.php` (`EVENT_BUILD`, `defaults()`) |
| прежний чат (перенос, 1.49.0) | `lib/assistant/sidepanel.php`, `lib/kb/chatstream.php`, `endpoint/kb.php`, `site/app/components/HdChatOverlay.vue` |
| прежние права чата (1.49.0) | `lib/access/actions.php` (`assistant.use`, `assistant.panel`), таблицы `shef_toolsai_access_permission`, `shef_toolsai_access_code` |
