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

## Что берётся у shef.toolsai (только публичное)

| класс и метод | зачем | где у нас |
|---|---|---|
| событие `shef.toolsai:OnPromptRegistryBuild` | регистрация шести ролей | `Internals\Integration\ToolsAi\EventHandler\OnPromptRegistryBuildEventHandler` |
| `Prompt\RegistryService::registry()->roles(?array $allowed)` | роли для переключателя и страницы прав | `Chat\Gateway\ToolsAiPromptSource` |
| `Prompt\RegistryService::compose($role, $place, $entityTypeId, $allowed)` | текст роли (+ её навык) | там же |
| `Prompt\RegistryService::forget()` | перечитать реестр после установки | `install/index.php` |
| `Prompt\Definition` (`PLACE_LIST`, `PLACE_DOCS`, поля `code/title/text/parent/examples/description`) | разбор ответа реестра | `ToolsAiPromptSource` |
| `Analysis\RecordHistory::get($entityTypeId, $id, $userId)` | история записи с правами CRM | `Chat\Gateway\ToolsAiRecordSource` |
| `Container::getLlm(Config::DIRECTION_ASSISTANT)` | модель направления «Чат ИИ-помощника» | `Chat\Gateway\ToolsAiModelGateway` |
| `LlmProviderInterface::complete()`, `getCode()`; `LlmResult` (`text`, `finishReason`, `costMicro`, `getTokens()`) | вызов модели | там же |
| `Main\Constants::PROVIDER_ECHO`, `getEngineCode()` | заглушка; код движка в журнале | там же |
| `Container::getMeter()->getMonthly()->isExceeded()` | квота до вызова | там же |
| `Quota\Ledger::start()` / `finish()`, `Quota\Status::SUCCESS/ERROR` | журнал расхода | там же |
| `Provider\ProviderException` (`spentUnits`, `spentMicro`) | оплаченный, но негодный ответ — в журнал | там же |
| `Container::getConfig()->getAssistantEndpoint()->model` | название модели под окном | там же |
| `Log\Handlers::withoutFiles()` | лог без файлов, как у shef.toolsai | `Container::getLogger()` |

Порядок вызова модели — навык `shef-new-ai-provider`, раздел «Вызвать LLM из
своего модуля».

## Чего в публичном API shef.toolsai не хватает

1. **Фасада «вызвать LLM с учётом расхода»** — сейчас каждый модуль повторяет
   `Ledger::start()` → `complete()` → `finish()` и разбор `ProviderException`.
   Нужен `Public\Service\LlmService::complete(string $category, array $messages): LlmResult`
   с квотой и журналом внутри.
2. **Стабильного публичного namespace**: всё выше — внутренние классы shef.toolsai
   (`Container`, `Quota\Ledger`, `Prompt\RegistryService`), их переименование сломает
   модуль молча. Нужен `Shef\ToolsAi\Public\…` с обещанием совместимости.
3. **Места «чат помощника» в реестре** (`Definition::PLACES`): есть только
   `docs/list/card`. Навыки shef.toolsai без роли (`docs`, `record`) подходят к любой
   роли, поэтому модуль их отфильтровывает сам (берёт навык только с `parent` = роль).
4. **Категории `assistant` в подписях страницы расхода** shef.toolsai: строки чата
   видны там под сырым кодом категории.
5. **Действия строки в виде «ИИ»**: вид «ИИ» (`view/page.php`) после выноса чата не
   выводит «✦ Спросить ИИ». Нужно событие вида (например,
   `shef.toolsai:OnViewRowActionsBuild`) или вызов `Public\Service\ChatPanel` из вида
   при установленном модуле ([06-api.md](06-api.md)). Кнопку «✦ ИИ: чат» и панель на
   `/shef/toolsai/view/` модуль добавляет сам.
6. **Потокового метода в `LlmProviderInterface`** (`completeStream()` есть только у
   `OpenAi\Llm`): поэтому ответ — одним куском.
