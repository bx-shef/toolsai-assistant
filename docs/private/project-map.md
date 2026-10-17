# Карта проекта

> Последняя сверка: 2026-10-17

## Цель

Чат ИИ-помощника с ролями для анализа работы сотрудников, узких мест и качества
ведения сделки — отдельным модулем, на движке shef.toolsai.

## Сделано (1.0.0)

- Перенос панели справа, кнопки, `window.ShefAiChat`, «✦ Спросить ИИ» из shef.toolsai.
- Окно чата — JS-расширение; контроллер ответа; шесть ролей в реестре промптов.
- Права на `ui.accessrights.v2`: чат и роли; выбор роли на сотрудника и чат.
- Статьи shef.toolsaikb — мягко.
- Публичный API shef.toolsai (`LlmService`, `PromptService`, `RecordService`; в 1.0.0 —
  1.50.0, с 1.6.0 модуля — 1.54.0: `roles`/`isAvailable`/`compose` с `$userId`),
  «✦ Спросить ИИ» в виде «ИИ» событием `OnViewRowActionsBuild`. Перенос прав
  чата из shef.toolsai убран в 1.2.0 — модуль на порталах уже стоит.

## Сделано (1.5.0)

- «Оценки ответов» — штатные грид и фильтр, сводка «доля полезно по ролям» со
  ссылкой в «Библиотеку промптов», окно оценки с ответом в разметке чата.
- Разбор оценки (право `feedback.edit`): статус, «что должно было быть», выгрузка
  кейсов проверки промптов shef.toolsai (задача `chat`, обезличено).
- Ждёт shef.toolsai: задача `chat` в `tools/prompt-eval.php`
  ([guide/08-feedback.md](../guide/08-feedback.md), «Что нужно от shef.toolsai»).

## Сделано (1.1.0–1.4.0)

- Ответ печатается по ходу: действие `chat.stream` (`text/event-stream`),
  модель — `LlmService::stream()` shef.toolsai (1.1.0).
- Оценки «Полезно / Не помогло», страница «Оценки ответов», срок хранения (1.1.0).
- Обновление без установщика — `Main\Maintenance` (1.1.0).
- «Где показывать чат» — подбор мест, событие `OnChatPlacesCollect`, чат из
  справки shef.toolsai (1.3.0); кнопка в виде «ИИ» событием `OnViewToolbarBuild` (1.3.1).
- Вид как у BitrixGPT в мессенджере, диалог «Выбрать роль», «✦ Спросить ИИ» в
  шапке карточки CRM (1.4.0).

## Сделано (1.5.1–1.6.0)

- Пункты в шестерёнке вида «ИИ» событием `OnViewSettingsMenuBuild` (1.5.1).
- Кому открыта роль — решает «Кому доступна» в библиотеке промптов
  shef.toolsai 1.54.0; раздел «Роли помощника» со страницы прав убран; иконки
  ролей из библиотеки; готовые вопросы «с уточнениями» встают в поле ввода (1.6.0).
- Задача `chat` в проверке промптов shef.toolsai — сделана в shef.toolsai 1.58.0:
  выгрузка оценок как кейсов теперь идёт в раннер.

## Порядок выкладки (обязательно)

1. **Сначала shef.toolsai 1.54.0** (с 1.6.0 этого модуля; раньше — 1.50.0) — выпуск без своей панели чата, кнопки «ИИ: чат»
   и прав «Чат ИИ-помощника». Иначе на странице две панели и два `window.ShefAiChat`.
   Установщик этого модуля на shef.toolsai ниже 1.54.0 не ставится.
2. **Потом shef.toolsaiassistant** — установка, затем права на
   `/shef/toolsaiassistant/rights/`.
3. По желанию — shef.toolsaikb (статьи в ответах), в любой момент.

Удаление — в обратном порядке: shef.toolsai не даст себя удалить, пока стоит этот
модуль (`requireModules`).

## Линейка ИИ: зависимости и связь событиями

С 1.50.0 shef.toolsai разделён на четыре модуля. Зачем: баннеры, база знаний
и чат ставятся по желанию и выпускаются отдельно, а основной модуль от них
не зависит. Связь — только событиями D7 (`Bitrix\Main\Event`).

Сверено с кодом 2026-10-17; версии — на портале stuttgart (выкладка 3.72).

| модуль | репозиторий | версия | обязательно нужен | по желанию |
|---|---|---|---|---|
| `shef.toolsai` | [bx-shef/toolsai](https://github.com/bx-shef/toolsai) | 1.58.0 | `shef.options`, `shef.problems`; модули ядра `ai`, `crm` — проверка в установщике | — |
| `shef.toolsaiassistant` | [bx-shef/toolsai-assistant](https://github.com/bx-shef/toolsai-assistant) | 1.6.0 | `shef.options`, `shef.problems`, `shef.toolsai` (модель, квота, журнал расхода, реестр промптов, история записи) | `shef.toolsaikb` — поиск статей для ответа |
| `shef.toolsaiannounce` | [bx-shef/toolsai-announce](https://github.com/bx-shef/toolsai-announce) | 1.4.1 | `shef.options`, `shef.problems` | — |
| `shef.toolsaikb` | [bx-shef/toolsai-kb](https://github.com/bx-shef/toolsai-kb) | 1.2.0 | `shef.options`, `shef.problems`; модуль ядра `note` 26.250.0+ — проверка в установщике | — |

| событие | кто шлёт | кто отвечает | что передаёт |
|---|---|---|---|
| `shef.toolsai:OnPromptRegistryBuild` | toolsai | assistant | роли чата в реестр промптов |
| `shef.toolsai:OnPromptChanged` | toolsai | — (для своих модулей) | код изменённого промпта |
| `shef.toolsai:OnPromptPlacesCollect` | toolsai | — (для своих модулей) | места «Где доступен» промпта |
| `shef.toolsai:OnViewRowActionsBuild` | toolsai | assistant | пункт строки в виде «ИИ» |
| `shef.toolsai:OnViewToolbarBuild` | toolsai | assistant | кнопка в шапке вида «ИИ» |
| `shef.toolsai:OnViewSettingsMenuBuild` | toolsai | assistant, announce, kb | пункты шестерёнки вида «ИИ» |
| `shef.toolsaiannounce:OnAnnouncementsCollect` | announce | toolsai | объявления по умолчанию |
| `shef.toolsaiannounce:OnAnnouncementPlacesCollect` | announce | — (для своих модулей) | места показа объявлений |
| `shef.toolsaikb:OnKbSourcesCollect` | kb | toolsai | пакет статей справки (`shef.toolsai:guide`) |
| `shef.toolsaiassistant:OnChatPlacesCollect` | assistant | toolsai | места «Где показывать чат» |

Подробно: публичный API и события toolsai —
[guide/32](https://github.com/bx-shef/toolsai/blob/main/docs/guide/32-public-api.md),
реестр промптов —
[guide/31](https://github.com/bx-shef/toolsai/blob/main/docs/guide/31-prompt-registry.md).

## Открытые вопросы

- Проверка на стенде: страница прав (ID прав с двоеточием в `ui.accessrights.v2`),
  кнопка в ui.toolbar на страницах CRM, `CloseSession` с длинным ответом модели.
- После обновления до 1.6.0 роли «Контроль руководителя» и «Узкие места» открыты
  всем, у кого есть чат, пока администратор не закроет их в «Кому доступна»
  (см. CHANGELOG 1.6.0). На портале stuttgart — проверить, закрыты ли.

Решено и снято с этого списка: ответ потоком (1.1.0), шлюзы на публичный API
shef.toolsai (bx-shef/toolsai#211 смержен), задача `chat` в раннере shef.toolsai
(1.58.0).
