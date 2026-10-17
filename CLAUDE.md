# Памятка агенту: shef.toolsaiassistant

> Последняя сверка: 2026-10-17

Модуль коробочного Битрикс24: чат ИИ-помощника панелью справа в CRM с ролями
(анализ работы сотрудников, узкие места, качество ведения сделки). Вынесен из
`shef.toolsai` (там чат жил с 1.44.0 по 1.49.0). Канон линейки — как у
[shef.toolsai](https://github.com/bx-shef/toolsai/blob/main/CLAUDE.md) и
[shef.options](https://github.com/bx-shef/options/blob/main/CLAUDE.md): всё, что там
сказано про устройство модуля линейки, верно и здесь.

Проверка перед сдачей — всегда `./build.sh --check`, ровно это же гоняет CI.
Как работа попадает в `main` — [docs/private/agent-rules.md](docs/private/agent-rules.md).
Документацию правим **в том же PR**, что и код.

## Зависимости — строго

- `shef.options`, `shef.problems`, `shef.toolsai` — в `requireModules`: без них модуль
  не грузится. От `shef.toolsai` берём **только публичное** — список в
  [docs/guide/03-dependencies.md](docs/guide/03-dependencies.md). Toolsai не правим из
  этого репозитория; не хватает API — пишем требование туда же.
- `shef.toolsaikb` — **мягко**: `Loader::includeModule` + `class_exists`
  (`Chat\Gateway\KbSource`). Нет модуля — чат отвечает без статей.
- `shef.toolsai` про этот модуль не знает. Связь — событием
  `shef.toolsai:OnPromptRegistryBuild` (роли) и JS-глобалом `window.ShefAiChat`.

## Опорные точки

| что | где |
|---|---|
| роли помощника (тексты, коды, заготовки прав) | `lib/prompt/roles.php` |
| обработчик реестра промптов | `lib/internals/integration/toolsai/eventhandler/` |
| панель на страницах CRM | `lib/internals/integration/main/eventhandler/onprologeventhandler.php` |
| сценарий ответа (права → роль → квота → запись → kb → модель) | `lib/chat/answerservice.php` |
| вызов модели (`LlmService`: квота, журнал расхода) | `lib/chat/gateway/toolsaimodelgateway.php` |
| «✦ Спросить ИИ» в виде «ИИ» | `lib/internals/integration/toolsai/eventhandler/onviewrowactionsbuildeventhandler.php` |
| «✦ ИИ: чат» в шапке вида «ИИ» (событие `OnViewToolbarBuild`, 1.3.1) | `lib/internals/integration/toolsai/eventhandler/onviewtoolbarbuildeventhandler.php` |
| «✦ Спросить ИИ» в шапке карточки CRM (Toolbar API из OnProlog, 1.4.0) | `lib/public/service/chatpanel.php` (`addRecordToolbarButton()`), разбор адреса — `lib/panel/sidepanel.php` (`detailRecord()`) |
| ширина и место панели (поверх, clamp, место под полосу мессенджера; 1.4.0) | `install/js/shef/toolsaiassistant/chat/layout.js` |
| вид переписки (карточка роли, пузыри, время; 1.4.0) | `install/js/shef/toolsaiassistant/chat/view.js` |
| диалог «Выбрать роль» (модель — `roledialog.js`, вид — `chat.js` `openRoles()`), недавние/избранное | `lib/chat/roleprefs.php`, `roleprefsstore.php` (CUserOptions), иконка роли — `lib/chat/roleicon.php` |
| контроллер окна | `lib/controller/chat.php` (`shef:toolsaiassistant.chat.info/role/stream/answer/rate/favorite`) |
| ответ потоком | `lib/chat/streamresponse.php`, `streamevents.php`; окно — `install/js/.../chat/stream.js` |
| оценки ответов | `lib/feedback/*` (таблица, разбор, страница `/shef/toolsaiassistant/feedback/`); с 1.5.0 — грид и фильтр (`GridFilter`), сводка по ролям (`RoleSummary`), статус и «что должно было быть» (`Review`), кейсы проверки (`EvalCase`, `Anonymizer`), контроллер `lib/controller/feedback.php`, окно — `install/js/shef/toolsaiassistant/feedback/` |
| права | `lib/access/*`, страница — компонент `install/components/shef/toolsaiassistant.rights` |
| окно чата | `install/js/shef/toolsaiassistant/chat/` (`chat.js`, `markdown.js`, `chat.css`) |
| публичный API для других модулей | `lib/public/service/chatpanel.php` |
| места «Где показывать чат» (1.3.0) | `lib/panel/placeurl.php` (сопоставление), `placecatalog.php` (свои + событие `OnChatPlacesCollect`), `placesoption.php` (поле настроек), провайдер `lib/internals/integration/ui/entityselector/placeprovider.php` |

## Решения и ловушки

- **Право роли проверяет сервер на каждом ответе** (`Chat\RoleSelector`): присланная
  роль, не открытая сотруднику, — отказ, а не тихая подмена. Запомненная, но
  закрытая потом роль — не берётся.
- **От shef.toolsai — только `Shef\ToolsAi\Public\…`** (1.50.0): `LlmService`,
  `PromptService` (место `PLACE_ASSISTANT`), `RecordService`, событие
  `OnViewRowActionsBuild`. Внутренние классы shef.toolsai не трогаем.
- **Переноса прав из shef.toolsai нет** (убран в 1.2.0): опцию `LEGACY_migrated`
  удаляет `Main\Maintenance`; роль «Чат (перенос из shef.toolsai)» не трогаем.
- **Ответ — потоком** (1.1.0): действие `stream` возвращает `Chat\StreamResponse`
  (наследник `HttpResponse` со своим `send()` — так ядро отдаёт `File`/`Zip`).
  Все проверки — до потока, отказ — обычным JSON ajax; сессия отпускается до
  вызова модели (`ActionFilter\CloseSession`). `answer` (одним JSON) оставлен.
- **Оценки ответов** — своя таблица `shef_toolsaiassistant_feedback`; смотреть —
  право `feedback.view` (администратору всегда). Таблица создаётся и при первой
  оценке после обновления (`FeedbackStore::ensure()`).
- **Оценки → кейсы проверки промптов shef.toolsai** (1.5.0, [docs/guide/08-feedback.md](docs/guide/08-feedback.md)):
  разбирать — право `feedback.edit` (тянет `feedback.view`); «В наборе проверки» ставит
  только выгрузка; кейс — задача `chat`, обезличен `Anonymizer`. Задачу `chat` в
  `tools/prompt-eval.php` делает shef.toolsai, не мы. Новые колонки старой таблицы —
  `TableInstaller::addColumns()` (createDbTable() их не добавит).
- **Обновление без установщика** (`Main\Maintenance::ensureOnce()` в OnProlog):
  версия файлов (`FILES_version`) отстала — перекопировать свои каталоги installDir,
  таблицы, подписки, агент (`tests/maintenance_test.php`). Новый файл в install/ —
  он приедет и так; новая таблица — добавить в `Maintenance::ensure()`.
- **«Где показывать чат» пусто — чат нигде** (1.1.0); умолчание — в `default_option.php`.
  С 1.3.0 поле — подбор мест (`ui.entity-selector`), но хранит то же: адреса
  строками в `PANEL_paths`; ключ места — адрес, а не id.
- **Чат поверх слайдеров и из слайдера** (1.3.0): панель — в стопке
  `BX.ZIndexManager`; во фрейме (`IFRAME=Y`) `window.ShefAiChat` — посредник к
  верхнему окну (`chat.js`, `Frame`). Чат справки — `open({context: 'docs'})`.
- **Панель не сдвигает страницу** (1.4.0): никакого `padding` у `body` — по полосе
  мессенджера (`#right-bar`) Air-слайдер считает ширину и сжимался. Слой —
  в `ZIndexManager` **без** `alwaysOnTop` (прятал попапы ядра); поднимаем
  `bringToFront()` при открытии чата и слайдера, кроме слайдера из ссылки чата.
- **Кнопка карточки CRM — не в меню-шестерёнке** (владелец, 1.4.0): отдельной
  кнопкой Toolbar API из OnProlog. Меню `intranet.binding.menu` кешируется общим
  на всех — право сотрудника там не проверить.
- **Диалог ролей — слоем внутри панели**, не `ai.roles-dialog`: тот берёт роли
  модуля ai (`ai.engine`), а окно `ui.entity-catalog` легло бы
  над панелью. Вид повторяем по его CSS.
- **Ответ модели в окне** экранируется целиком, ссылки — только `/…` и http(s)
  (`markdown.js`, `tests/markdown_test.mjs`).
- **Удаление убирает только своё**: `customPathUnInstall` в `installDir` —
  `/bitrix/js/shef/toolsaiassistant`, `/local/components/shef/toolsaiassistant.rights`,
  `/shef/toolsaiassistant` (`tests/install_test.php`).
- **Частота ответов** ограничена на сотрудника (`Chat\RateLimit`, `CHAT_ratelimit`):
  отметка ставится до вызова модели — сбои тоже считаются.
- **Выкладка**: сначала shef.toolsai 1.50.0, потом этот модуль
  ([docs/private/project-map.md](docs/private/project-map.md)).
- Фильтры контроллеров — через `configureActions()`, не атрибутами: модуль
  ставится на main от 22.600.

## Тесты

`tests/*_test.php` и `tests/*_test.mjs`, обвязка `tests/assert.php` (как у линейки),
заглушки ядра — `tests/stub/`. Сценарий чата тестируется на подменах шлюзов
(`answerservice_test.php`); шлюз модели — на подмене shef.toolsai
(`tests/stub/toolsai.php`, `modelgateway_test.php`); что подменённое API
существует — `toolsaiapi_test.php` (по исходникам shef.toolsai, если они рядом).
