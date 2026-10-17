# 03. Зависимости и API shef.toolsai

> Последняя сверка: 2026-10-17

| модуль | как | зачем |
|---|---|---|
| `shef.options` 3.0+ | жёстко (`requireModules`) | страница настроек |
| `shef.problems` 2.0+ | жёстко | лог проблем (журнал событий Битрикс24) |
| `shef.toolsai` 1.54.0+ | жёстко (`NEED_MODULES_BY_VERSION` установщика и `Main\Maintenance::NEED_TOOLSAI` при обновлении без установщика) | модель, квота, журнал расхода, библиотека промптов с доступом к ролям, история записи |
| `shef.toolsaikb` | мягко | статьи базы знаний; нет — чат без статей |
| `crm`, `ui` | проверка установщика | история записи и права CRM; страница прав и кнопка |

shef.toolsai от этого модуля не зависит: связь — событием реестра промптов
([04-events.md](04-events.md)) и JS-глобалом `window.ShefAiChat`.

## Что берётся у shef.toolsai — только публичный API 1.54.0

Пространство имён `Shef\ToolsAi\Public\…` — его shef.toolsai обещает не менять в
пределах 1.x (глава 32 его документации, «Публичный API и модули-соседи»).
Внутренние классы (`Container`, `Quota\Ledger`, `Prompt\Registry`…) модуль не трогает.

| класс и метод | зачем | где у нас |
|---|---|---|
| событие `shef.toolsai:OnPromptRegistryBuild` (`RegistryService::EVENT_BUILD`) | регистрация шести ролей и типовых запросов меню промптов (1.7.0, `Prompt\TypicalPrompts`) | `Internals\Integration\ToolsAi\EventHandler\OnPromptRegistryBuildEventHandler` |
| событие `shef.toolsai:OnViewRowActionsBuild` (`Public\Event\ViewRowActionsBuildEvent`) | «✦ Спросить ИИ» в меню строки вида «ИИ» | `Internals\Integration\ToolsAi\EventHandler\OnViewRowActionsBuildEventHandler` |
| событие `shef.toolsai:OnViewToolbarBuild` (`Public\Event\ViewToolbarBuildEvent`, 1.53.0) | «✦ ИИ: чат» в шапке вида «ИИ»; мягко — нет события, кнопку ставит `OnProlog` | `Internals\Integration\ToolsAi\EventHandler\OnViewToolbarBuildEventHandler` |
| событие `shef.toolsai:OnViewSettingsMenuBuild` (`Public\Event\ViewSettingsMenuBuildEvent`, 1.53.0) | пункты «ИИ чат с ролями: настройки» и «Оценки ответов» в меню шестерёнки вида «ИИ»; мягко — нет события, нет пунктов | `Internals\Integration\ToolsAi\EventHandler\OnViewSettingsMenuBuildEventHandler` |
| `Public\Service\PromptService::roles(null, $userId)` (1.54.0; `$userId > 0` — явно, не `USER_CURRENT`: без `$USER` он стал бы `USER_ANY`; `<= 0` — отказ) | роли для окна и диалога «Выбрать роль»: только открытые сотруднику «Кому доступна», без скрытых им, избранные первыми (`favorite`). Поля: `icon` — `set:<имя ui.icon-set>` или `file:<ID>` (`Chat\RoleIcon::of`; нет — по коду роли), `iconUrl` — картинка для `file:` (`Chat\RoleIcon::url`: путь портала или https), `group` — группа в диалоге, до 60 знаков | `Chat\Gateway\ToolsAiPromptSource` |
| `PromptService::roles(null, PromptService::USER_ANY)` (1.54.0) | названия всех включённых ролей для фильтра страницы оценок (`ToolsAiPromptSource::titles()`): доступ к роли там не нужен | `Feedback\FeedbackPage` |
| `PromptService::isAvailable($role, $userId)` (1.54.0) | проверка роли на каждом ответе и у оценки | там же |
| `PromptService::compose($role, PromptService::PLACE_ASSISTANT, $entityTypeId, null, $userId)` | текст роли (+ её навык), готовые вопросы, `role` — какую роль взяла библиотека (не та — отказ смены роли), `type` навыка: `template` — его текст (`PromptService::get($skill)['text']`, только если `isAvailable($skill, $userId)`) уходит в окно шаблоном для поля ввода | там же |
| `PromptService::get($code)` | текст навыка «с уточнениями» | `Chat\Gateway\ToolsAiPromptSource` |
| `PromptService::skills($role, $places, $entityTypeId, $userId)` (1.59.0; мягко — `method_exists`, старее — меню промптов не появится) | меню промптов в поле ввода (1.7.0): навыки роли и общие навыки мест окна — `shef.toolsaiassistant:chat`, `:chat.list`, `:chat.card` (`Prompt\ChatPlaces`) | `Chat\Gateway\ToolsAiPromptSource::prompts()` |
| событие `shef.toolsai:OnPromptPlacesCollect` (`Prompt\Places::EVENT`, 1.54.0) | места чата в «Где доступен» мастера библиотеки промптов: администратор кладёт свой промпт в меню чата | `Internals\Integration\ToolsAi\EventHandler\OnPromptPlacesCollectEventHandler` |
| `PromptService::forget()` | перечитать реестр после установки | `install/index.php` |
| `Public\Service\RecordService::get($entityTypeId, $id, $userId)` | история записи с правами CRM; с 1.5.0 — и запись в кейсе проверки при выгрузке оценок | `Chat\Gateway\ToolsAiRecordSource` |
| `Public\Service\LlmService::stream(LlmService::CATEGORY_ASSISTANT, $messages, $onDelta, $userId)` | ответ потоком (окно, 1.1.0); квота до вызова и журнал расхода — внутри | `Chat\Gateway\ToolsAiModelGateway` |
| `Public\Service\LlmService::complete(LlmService::CATEGORY_ASSISTANT, $messages, $userId)` | ответ одним куском (действие `answer`) | `Chat\Gateway\ToolsAiModelGateway` |
| `LlmService::model(CATEGORY_ASSISTANT)` (`''` — заглушка) | подпись под окном; заглушка — подсказка без вызова | там же |
| `LlmResponse` (`isSuccess()`, `getText()`, `getFinishReason()`, `getErrorCode()`), коды `ERROR_QUOTA`, `ERROR_NO_MODEL`, `ERROR_EMPTY`, `ERROR_PROVIDER` | ответ и отказы → `ChatException` (`quota`, `model`) | там же |

shef.toolsai старее 1.54.0 (обновили файлы без установщика): шаг `toolsai` в
`Main\Maintenance` не проходит и повторяется на каждом хите, а чат отвечает отказом
«нужен shef.toolsai 1.54.0» — роли не открываются всем без проверки.

Что эти классы и методы существуют — `tests/toolsaiapi_test.php` (по исходникам
shef.toolsai, если они рядом; иначе — сверка списка с этой таблицей).

## Чего ещё не хватает

**Таймаут прокси для потока.** Пока модель думает, PHP ждёт внутри
`LlmService::stream()` и ничего в поток не пишет (`: ping` уходит только в начале).
Таймаут чтения nginx (`proxy_read_timeout` / `fastcgi_read_timeout`, обычно 60 с)
должен быть больше таймаута модели в настройках shef.toolsai, иначе nginx оборвёт
долгий ответ — окно покажет «Ответ модели оборвался».

Поток в окне сделан в 1.1.0 (`LlmService::stream()`) — из этого списка убран.
