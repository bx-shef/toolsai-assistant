# 04. События

> Последняя сверка: 2026-10-16

Постоянные подписки — в `installEvents` (`.settings.php`): установщик
регистрирует их, удаление снимает те же (`tests/install_test.php`).

| событие | обработчик | что делает |
|---|---|---|
| `shef.toolsai:OnPromptRegistryBuild` (D7) | `Internals\Integration\ToolsAi\EventHandler\OnPromptRegistryBuildEventHandler::handle(Event): EventResult` | отдаёт шесть ролей: `EventResult(SUCCESS, ['prompts' => Roles::raw()], 'shef.toolsaiassistant')` |
| `main:OnProlog` (compatible, без параметров) | `Internals\Integration\Main\EventHandler\OnPrologEventHandler::handle()` | на страницах из настройки «Где показывать чат» — кнопка «✦ ИИ: чат» и панель |

Контракт `OnPromptRegistryBuild` задаёт shef.toolsai (`lib/prompt/registryservice.php`,
`RegistryService::defaults()`): коды промптов обязаны начинаться с ID модуля —
чужие реестр отбрасывает. После установки модуль зовёт `RegistryService::forget()`,
чтобы реестр перечитался.

`shef.toolsai:OnPromptChanged` модуль не слушает: своего кеша промптов у него нет,
текст роли берётся из реестра на каждом ответе.

Своих событий модуль не шлёт.
