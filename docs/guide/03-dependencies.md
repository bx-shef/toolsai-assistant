# 03. Зависимости и API shef.toolsai

> Последняя сверка: 2026-10-16

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
| `Public\Service\PromptService::roles(?array $allowed)` | роли для переключателя и страницы прав | `Chat\Gateway\ToolsAiPromptSource` |
| `PromptService::compose($role, PromptService::PLACE_ASSISTANT, $entityTypeId, $allowed)` | текст роли (+ её навык) | там же |
| `PromptService::forget()` | перечитать реестр после установки | `install/index.php` |
| `Public\Service\RecordService::get($entityTypeId, $id, $userId)` | история записи с правами CRM | `Chat\Gateway\ToolsAiRecordSource` |
| `Public\Service\LlmService::complete(LlmService::CATEGORY_ASSISTANT, $messages, $userId)` | модель «Чат ИИ-помощника»; квота до вызова и журнал расхода — внутри | `Chat\Gateway\ToolsAiModelGateway` |
| `LlmService::model(CATEGORY_ASSISTANT)` (`''` — заглушка) | подпись под окном; заглушка — подсказка без вызова | там же |
| `LlmResponse` (`isSuccess()`, `getText()`, `getFinishReason()`, `getErrorCode()`), коды `ERROR_QUOTA`, `ERROR_NO_MODEL`, `ERROR_EMPTY`, `ERROR_PROVIDER` | ответ и отказы → `ChatException` (`quota`, `model`) | там же |

Что эти классы и методы существуют — `tests/toolsaiapi_test.php` (по исходникам
shef.toolsai, если они рядом; иначе — сверка списка с этой таблицей).

## Права чата из shef.toolsai

Права shef.toolsai «Чат ИИ-помощника: спрашивать» (`assistant.use`) и «…: справа в
виде «ИИ»» (`assistant.panel`) в 1.50.0 убраны. При установке модуль один раз
переносит их (`Access\LegacyRights`): тем, кому они были выданы (коды из таблиц прав
shef.toolsai), создаётся роль доступа «Чат (перенос из shef.toolsai)» — «Пользоваться
чатом» и роль «Помощник менеджера». Таблиц нет или прав не было — роль не создаётся;
выдать чат — на странице прав ([02-rights.md](02-rights.md)).

## Чего ещё не хватает

1. **Поток в окне чата**: `LlmService::stream()` есть, но контроллер отвечает
   JSON — для потока нужен свой ответ (SSE) и окно с разбором потока.
