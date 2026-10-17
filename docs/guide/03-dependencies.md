# 03. Зависимости и API shef.toolsai

> Последняя сверка: 2026-10-17

| модуль | как | зачем |
|---|---|---|
| `shef.options` 3.0+ | жёстко (`requireModules`) | страница настроек |
| `shef.problems` 2.0+ | жёстко | лог проблем (журнал событий Битрикс24) |
| `shef.toolsai` 1.50.0+ | жёстко | модель, квота, журнал расхода, реестр промптов, история записи |
| `shef.toolsaikb` | мягко | статьи базы знаний; нет — чат без статей |
| `crm`, `ui` | проверка установщика | история записи и права CRM; страница прав и кнопка |

shef.toolsai от этого модуля не зависит: связь — событием реестра промптов
([04-events.md](04-events.md)) и JS-глобалом `window.ShefAiChat`.

## Что берётся у shef.toolsai — только публичный API 1.50.0

Пространство имён `Shef\ToolsAi\Public\…` — его shef.toolsai обещает не менять в
пределах 1.x (глава 32 его документации, «Публичный API и модули-соседи»).
Внутренние классы (`Container`, `Quota\Ledger`, `Prompt\Registry`…) модуль не трогает.

| класс и метод | зачем | где у нас |
|---|---|---|
| событие `shef.toolsai:OnPromptRegistryBuild` (`RegistryService::EVENT_BUILD`) | регистрация шести ролей | `Internals\Integration\ToolsAi\EventHandler\OnPromptRegistryBuildEventHandler` |
| событие `shef.toolsai:OnViewRowActionsBuild` (`Public\Event\ViewRowActionsBuildEvent`) | «✦ Спросить ИИ» в меню строки вида «ИИ» | `Internals\Integration\ToolsAi\EventHandler\OnViewRowActionsBuildEventHandler` |
| событие `shef.toolsai:OnViewToolbarBuild` (`Public\Event\ViewToolbarBuildEvent`, 1.53.0) | «✦ ИИ: чат» в шапке вида «ИИ»; мягко — нет события, кнопку ставит `OnProlog` | `Internals\Integration\ToolsAi\EventHandler\OnViewToolbarBuildEventHandler` |
| `Public\Service\PromptService::roles(?array $allowed)` | роли для диалога «Выбрать роль» и страницы прав. С 1.4.0 читаем необязательные поля роли: `icon` — код иконки `ui.icon-set` по шаблону `^[a-z][a-z0-9-]{1,60}$` (`o-person` и т.п.); нет или не подходит — подберём по коду роли (`Chat\RoleIcon`); `group` — группа в диалоге ролей, до 60 знаков. shef.toolsai может их отдавать, но не обязан | `Chat\Gateway\ToolsAiPromptSource` |
| `PromptService::compose($role, PromptService::PLACE_ASSISTANT, $entityTypeId, $allowed)` | текст роли (+ её навык) | там же |
| `PromptService::forget()` | перечитать реестр после установки | `install/index.php` |
| `Public\Service\RecordService::get($entityTypeId, $id, $userId)` | история записи с правами CRM; с 1.5.0 — и запись в кейсе проверки при выгрузке оценок | `Chat\Gateway\ToolsAiRecordSource` |
| `Public\Service\LlmService::stream(LlmService::CATEGORY_ASSISTANT, $messages, $onDelta, $userId)` | ответ потоком (окно, 1.1.0); квота до вызова и журнал расхода — внутри | `Chat\Gateway\ToolsAiModelGateway` |
| `Public\Service\LlmService::complete(LlmService::CATEGORY_ASSISTANT, $messages, $userId)` | ответ одним куском (действие `answer`) | `Chat\Gateway\ToolsAiModelGateway` |
| `LlmService::model(CATEGORY_ASSISTANT)` (`''` — заглушка) | подпись под окном; заглушка — подсказка без вызова | там же |
| `LlmResponse` (`isSuccess()`, `getText()`, `getFinishReason()`, `getErrorCode()`), коды `ERROR_QUOTA`, `ERROR_NO_MODEL`, `ERROR_EMPTY`, `ERROR_PROVIDER` | ответ и отказы → `ChatException` (`quota`, `model`) | там же |

Что эти классы и методы существуют — `tests/toolsaiapi_test.php` (по исходникам
shef.toolsai, если они рядом; иначе — сверка списка с этой таблицей).

## Чего ещё не хватает

**Таймаут прокси для потока.** Пока модель думает, PHP ждёт внутри
`LlmService::stream()` и ничего в поток не пишет (`: ping` уходит только в начале).
Таймаут чтения nginx (`proxy_read_timeout` / `fastcgi_read_timeout`, обычно 60 с)
должен быть больше таймаута модели в настройках shef.toolsai, иначе nginx оборвёт
долгий ответ — окно покажет «Ответ модели оборвался».

Поток в окне сделан в 1.1.0 (`LlmService::stream()`) — из этого списка убран.
