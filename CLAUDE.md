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
- `shef.toolsaieval` — **мягко** (1.12.0, `Chat\Gateway\ToolsAiEvalGateway`): оценки — в
  общий журнал отзывов; нет модуля — в свою таблицу.
- `shef.toolsai` про этот модуль не знает. Связь — событием
  `shef.toolsai:OnPromptRegistryBuild` (роли) и JS-глобалом `window.ShefAiChat`.

## Опорные точки

| что | где |
|---|---|
| роли помощника (тексты, коды, заготовки ролей доступа) | `lib/prompt/roles.php` |
| обработчик реестра промптов | `lib/internals/integration/toolsai/eventhandler/` |
| панель на страницах CRM | `lib/internals/integration/main/eventhandler/onprologeventhandler.php` |
| сценарий ответа (права → роль → квота → запись → kb → модель) | `lib/chat/answerservice.php` |
| вызов модели (`LlmService`: квота, журнал расхода) | `lib/chat/gateway/toolsaimodelgateway.php` |
| «✦ Спросить ИИ» в виде «ИИ» | `lib/internals/integration/toolsai/eventhandler/onviewrowactionsbuildeventhandler.php` |
| «✦ ИИ: чат» в шапке вида «ИИ» (событие `OnViewToolbarBuild`, 1.3.1) | `lib/internals/integration/toolsai/eventhandler/onviewtoolbarbuildeventhandler.php` |
| пункты в меню шестерёнки вида «ИИ» (событие `OnViewSettingsMenuBuild`, 1.5.1) | `lib/internals/integration/toolsai/eventhandler/onviewsettingsmenubuildeventhandler.php` |
| «✦ Спросить ИИ» в шапке карточки CRM (Toolbar API из OnProlog, 1.4.0) | `lib/public/service/chatpanel.php` (`addRecordToolbarButton()`), разбор адреса — `lib/panel/sidepanel.php` (`detailRecord()`) |
| ширина и место панели (поверх, clamp, место под полосу мессенджера; 1.4.0) | `install/js/shef/toolsaiassistant/chat/layout.js` |
| вид переписки (карточка роли, пузыри, время; 1.4.0) | `install/js/shef/toolsaiassistant/chat/view.js` |
| диалог «Выбрать роль» (модель — `roledialog.js`, вид — `chat.js` `openRoles()`), недавние/избранное | `lib/chat/roleprefs.php`, `roleprefsstore.php` (CUserOptions), иконка роли — `lib/chat/roleicon.php` |
| что открыто у сотрудника (1.13.0): путь слайдера или окна → блок «ЧТО ОТКРЫТО» и подсказки агенту по инструментам MCP | `lib/chat/pagecontext.php`, окно — `chat.js` `pageOf()`, глава 10 |
| контроллер окна | `lib/controller/chat.php` (`shef:toolsaiassistant.chat.info/role/stream/answer/rate/favorite/greet`) |
| ответ потоком | `lib/chat/streamresponse.php`, `streamevents.php`; окно — `install/js/.../chat/stream.js` |
| оценки ответов | `lib/feedback/*` (таблица, разбор, страница `/shef/toolsaiassistant/feedback/`); с 1.5.0 — грид и фильтр (`GridFilter`), сводка по ролям (`RoleSummary`), статус и «что должно было быть» (`Review`), кейсы проверки (`EvalCase`, `Anonymizer`), контроллер `lib/controller/feedback.php`, окно — `install/js/shef/toolsaiassistant/feedback/` |
| общий журнал отзывов shef.toolsaieval (1.12.0): куда пишется оценка, перенос старых | `lib/feedback/feedbackrouter.php`, `evalmigration.php`, `evalbridge.php`, шлюз `lib/chat/gateway/toolsaievalgateway.php`, вид — `lib/internals/integration/toolsaieval/eventhandler/` |
| права | `lib/access/*`, страница — компонент `install/components/shef/toolsaiassistant.rights` |
| окно чата | `install/js/shef/toolsaiassistant/chat/` (`chat.js`, `markdown.js`, `chat.css`) |
| публичный API для других модулей | `lib/public/service/chatpanel.php` |
| меню промптов в поле ввода (1.7.0): места окна, типовые запросы, группы | `lib/prompt/chatplaces.php`, `lib/prompt/typicalprompts.php`, `install/js/.../chat/promptmenu.js`, глава 09 |
| виджеты в ответах (1.7.0): блок ```widget, реестр, событие `OnChatWidgetsCollect` | `markdown.js` (`renderFull`), `install/js/.../chat/widgets.js`, `lib/chat/widgetcatalog.php`, глава 09 |
| виджеты 1.11.0: воронка, оценка, пропущенный звонок, задачи, сегодня, саппорт, день рождения (`greet`, `Chat\Greeting`, `Chat\Gateway\ImMessenger`), документация, расписание дня, записи на приём (модуль booking) | `widgets.js`, `lib/chat/widgetcatalog.php`, глава 09 |
| файлы в чате (1.8.0): текст из PDF/DOCX/XLSX/PPTX/CSV/TXT в браузере, пределы на сервере | `install/js/.../chat/files.js`, `lib/chat/chatfiles.php`, глава 09 |
| режим «Агент» (1.10.0): цикл инструментов MCP, подтверждение записи, события модулей | `lib/agent/` (`AgentService`, `ToolProtocol`, `ToolCatalog`, `AgentEvents`), окно — `install/js/.../chat/agent.js`, контроллер `lib/controller/agent.php`, глава 10 |
| чат как клиент MCP-сервера toolsai-mcp (1.10.0): OAuth с PKCE, токены сотрудника зашифрованы | `lib/mcp/` (`McpConnection`, `OAuthClient`, `McpClient`, `TokenStore`), возврат входа — `/shef/toolsaiassistant/mcp/` |
| места «Где показывать чат» (1.3.0) | `lib/panel/placeurl.php` (сопоставление), `placecatalog.php` (свои + событие `OnChatPlacesCollect`), `placesoption.php` (поле настроек), провайдер `lib/internals/integration/ui/entityselector/placeprovider.php` |

## Решения и ловушки

- **Доступ к ролям — у библиотеки промптов shef.toolsai** (1.6.0, «Кому доступна»,
  shef.toolsai 1.54.0): свой раздел прав на роли убран, старые `role:<код>`
  удаляет `Main\Maintenance` (без переноса). Сервер на каждом ответе: роль из
  `PromptService::roles(null, $userId)` (`Chat\RoleSelector`) и
  `isAvailable($роль, $userId)` — сотрудник явно, не `-1`: без `$USER` это «без
  проверки». Администратор тоже подчиняется «Кому доступна».
  Присланная закрытая роль — отказ, а не тихая подмена.
- **Готовые вопросы и навык `type = template` — в поле ввода, не отправка** (1.6.0):
  `chat.js` `prefill()`, первое `[ … ]` выделено, Tab — к следующему
  (`View.placeholder`, `tests/chatinput_test.mjs`).
- **От shef.toolsai — только `Shef\ToolsAi\Public\…`** (1.54.0): `LlmService`,
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
- **Чат — как Марта, в правой колонке Air** (владелец, 1.9.0): панель встаёт в
  `#app__right-panel` через публичный `BX.Intranet.Bitrix24.Template.getRightPanel()`
  (`expand()`/`collapse()`), страница сужается сеткой шаблона, слайдер открывается
  левее (`getRightBoundary()` — по колонке). Ширину ведёт ядро. Марту сворачиваем
  штатно (`getRightPanelAiChat().collapse()`). Над слайдером **не поднимаем**,
  `data-a11y-ignore-inert` не ставим (колонка — в исключениях ловушки фокуса Air).
  Шаблон не Air и экран уже 768 — панель поверх у края, ниже слайдеров; в старом
  шаблоне сужается `.bx-layout-inner-table`. Никакого `padding` у `body`, `#right-bar`
  не трогаем. Во фрейме (`IFRAME=Y`) `window.ShefAiChat` — посредник к верхнему
  окну (`chat.js`, `Frame`). Чат справки — `open({context: 'docs'})`.
- **Кнопка карточки CRM — не в меню-шестерёнке** (владелец, 1.4.0): отдельной
  кнопкой Toolbar API из OnProlog. Меню `intranet.binding.menu` кешируется общим
  на всех — право сотрудника там не проверить.
- **Диалог ролей — слоем внутри панели**, не `ai.roles-dialog`: тот берёт роли
  модуля ai (`ai.engine`), а окно `ui.entity-catalog` легло бы
  над панелью. Вид повторяем по его CSS.
- **Ответ модели в окне** экранируется целиком, ссылки — только `/…` и http(s)
  (`markdown.js`, `tests/markdown_test.mjs`). Виджеты (1.7.0) — только DOM-узлы с
  `textContent`, без `innerHTML`; рисование модуля, вернувшее не узел, не вставляется.
- **Удаление убирает только своё**: `customPathUnInstall` в `installDir` —
  `/bitrix/js/shef/toolsaiassistant`, `/local/components/shef/toolsaiassistant.rights`,
  `/shef/toolsaiassistant` (`tests/install_test.php`).
- **Частота ответов** ограничена на сотрудника (`Chat\RateLimit`, `CHAT_ratelimit`):
  отметка ставится до вызова модели — сбои тоже считаются.
- **Выкладка**: сначала shef.toolsai 1.54.0, потом этот модуль
  ([docs/private/project-map.md](docs/private/project-map.md)).
- **Агент меняет данные только после «Выполнить»** (1.10.0): чтение или запись
  решает сервер MCP (`annotations.readOnlyHint`), ждущий вызов хранится на
  сервере (`Agent\PendingStore`, своя таблица, «забрать» атомарно — не личная
  опция: её сотрудник пишет сам), окно шлёт только id и решение — аргументы и
  шаги из окна не берём. Обновление токена MCP — под блокировкой сотрудника
  (`TokenStore::locked`): сервер вращает refresh. Протокол вызова — свой блок ```tool, а не tool
  calling провайдера: `LlmService` shef.toolsai — текст. Свои инструменты модули
  добавляют в MCP-сервер (манифест), а не сюда; здесь — только правила событием.
- **Поздравлять коллегу может каждый с «Пользоваться чатом»** (владелец, 2026-10-17;
  отдельного права нет). Защиты — на сервере (`greet`): настоящий получатель с
  портала в подтверждении, только сотрудник с днём рождения ±3 дня, без BB-кодов,
  5 в минуту. Типы виджетов по ролям — отдельной задачей (bx-shef/toolsai-assistant#21).
- Фильтры контроллеров — через `configureActions()`, не атрибутами: модуль
  ставится на main от 22.600.

## Тесты

`tests/*_test.php` и `tests/*_test.mjs`, обвязка `tests/assert.php` (как у линейки),
заглушки ядра — `tests/stub/`. Сценарий чата тестируется на подменах шлюзов
(`answerservice_test.php`); шлюз модели — на подмене shef.toolsai
(`tests/stub/toolsai.php`, `modelgateway_test.php`); что подменённое API
существует — `toolsaiapi_test.php` (по исходникам shef.toolsai, если они рядом).
