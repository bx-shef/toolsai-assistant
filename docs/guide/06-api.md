# 06. API для разработчиков

> Последняя сверка: 2026-10-17

## JS

```js
window.ShefAiChat.open({entityTypeId: 2, id: 15, title: 'Окна ПВХ'}); // чат по записи
window.ShefAiChat.open();                                               // общий чат
window.ShefAiChat.close();
window.ShefAiChat.toggle();                                             // открыть/закрыть (1.3.0)
window.ShefAiChat.isOpen();                                             // открыт ли (1.3.0)
```

Есть на страницах, где подключена панель. С 1.3.0 — и на странице в слайдере
(`IFRAME=Y`): там `window.ShefAiChat` — посредник, сам чат открывается в верхнем
окне. Если чата там нет, файлы расширения (из его `config.php`, с версией
`CUtil::GetAdditionalFileURL`) подгружаются туда через `top.BX.load` — **лениво**:
только по первому `open()`/`toggle()` или клику по кнопке, один раз на верхнее окно,
и без автооткрытия (`fromFrame`). Пока не загружено, в верхнем окне стоит
лёгкая заглушка `window.top.ShefAiChat` (`stub: true`): её вызов и запускает
загрузку. Поэтому у вложенной страницы без BX (сайт справки во фрейме слайдера)
`window.top.ShefAiChat` есть, как только в стопке открыта страница с панелью.

**Поверх слайдеров** (1.3.0): панель регистрируется в стопке `BX.ZIndexManager`
ядра (там же слои `BX.SidePanel`, `main/install/js/main/sidepanel/src/slider.js`),
при открытии чата и при открытии нового слайдера (`SidePanel.Slider:onOpen`,
`onOpenComplete`) поднимается наверх — не прячется под слайдером.

## JS: чат из справки (1.3.0)

Справка shef.toolsai (база знаний, Nuxt, во фрейме слайдера) зовёт чат мягко —
модуля может не быть:

```js
// из страницы справки (фрейм без BX)
try { window.top.ShefAiChat && window.top.ShefAiChat.open({
	context: 'docs',                      // обязательно: чат справки
	role: 'shef.toolsai:assistant',       // роль реестра; навык shef.toolsai:docs (места docs и assistant) подмешается
	title: 'Распознавание звонков',       // тема — заголовок окна, до 120 знаков
	question: 'Как включить на коробке?'  // по желанию: подставить в поле ввода, не отправлять
}); } catch(e) {}

// со страницы с BX — то же событием, без проверки наличия
BX.onCustomEvent('ShefAiChat:open', [{context: 'docs', role: 'shef.toolsai:assistant'}]);
```

| поле | правило |
|---|---|
| `context` | `'docs'` — чат справки; иначе это вызов по записи CRM |
| `role` | код роли (`[\w.:-]`, до 100); выбирается, только если открыт сотруднику — право проверяет сервер (`role`); иначе остаётся текущая роль |
| `title` | заголовок окна, по умолчанию «Справка» |
| `question` | текст в поле ввода, до 2000 знаков; не отправляется сам |

У чата справки свой ключ `docs` (`Chat\ChatKey::DOCS`): и переписка в браузере, и
запомненная роль на сервере — свои, общий чат не меняется. Окно шлёт
`context: 'docs'` во всех действиях (`info`, `role`, `stream`, `answer`, `rate`);
другой контекст сервер не принимает (`ChatKey::context()`), с записью CRM ключ —
записи. Разбор — `normalizeRecord()` в `chat.js`, `tests/chatopen_test.mjs`,
`tests/chat_test.php`, `tests/answerservice_test.php`.

Справке не нужно ничего знать о кнопке: если сотруднику чат не открыт или
модуля нет, `window.top.ShefAiChat` не будет — вызов просто ничего не сделает.

## PHP: места для «Где показывать чат»

Событие `shef.toolsaiassistant:OnChatPlacesCollect` — [04-events.md](04-events.md).
Сопоставление адреса со страницей — `Panel\PlaceUrl::matches($placeUrl, $requestUri)`.

## PHP: встроить чат в свою страницу

```php
use Bitrix\Main\Loader;
use Shef\ToolsAiAssistant\Public\Service\ChatPanel;

if(Loader::includeModule('shef.toolsaiassistant') && ChatPanel::canUse())
{
	ChatPanel::addToolbarButton();   // «✦ ИИ: чат» в ui.toolbar
	ChatPanel::load();               // расширение окна и запуск панели
	// действие строки main.ui.grid
	$actions[] = ['text' => ChatPanel::ROW_ACTION_TEXT, 'onclick' => ChatPanel::openRecordJs($entityTypeId, $id, $title)];
}
```

`openRecordJs()` экранирует данные для атрибута и `<script>`. Повторный `load()`
безвреден. На страницах из настройки «Где показывать чат» кнопку и панель модуль
добавляет сам, а «✦ Спросить ИИ» в вид «ИИ» shef.toolsai — событием
`OnViewRowActionsBuild` ([04-events.md](04-events.md)). Кнопка — штатная кнопка
тулбара с иконкой свёрнутого режима (`data-toolbar-collapsed-icon`, 1.3.1); скрипт
её не правит. В шапку вида «ИИ» (shef.toolsai 1.53+) кнопку ставит сам вид по
событию `OnViewToolbarBuild`.

## Ajax-действия

`BX.ajax.runAction('shef:toolsaiassistant.chat.<действие>', {data})` — POST,
авторизация, sessid.

| действие | параметры | ответ |
|---|---|---|
| `info` | `record: {entityTypeId, id}` или `{}` | `{roles: [{code, title, description}], role, examples, model, kb, chatKey}` |
| `role` | `role`, `record` | `{role, examples}`; роль не открыта — ошибка `role` |
| `answer` | `messages: [{role: user\|assistant, text}]`, `record`, `role` | `{text, role, sources: [{title, url}], finishReason}` — одним куском (совместимость с 1.0.0) |
| `stream` | как у `answer` | поток `text/event-stream` (1.1.0) — см. ниже |
| `rate` | `rating: up\|down`, `role`, `record`, `question`, `answer`, `comment` | `{id}` (1.1.0) |

Коды ошибок (`errors[0].code`): `access`, `role`, `no_roles`, `empty_question`,
`quota`, `rate`, `record`, `model`, `feedback`, `internal`. Текст ошибки можно
показывать сотруднику.

## Ответ потоком (1.1.0)

Окно печатает ответ по мере прихода: действие `stream`. `BX.ajax.runAction` поток
по кускам не отдаёт, поэтому окно зовёт тот же адрес ядра через `fetch`
(`/bitrix/services/main/ajax.php?action=shef:toolsaiassistant.chat.stream`, sessid в
заголовке `X-Bitrix-Csrf-Token`) — `install/js/.../chat/stream.js`.

Проверки модуля — права, роль, частота, запись CRM — **до** потока: отказ приходит
обычным JSON ajax с кодом ошибки, как у `answer`. **Квоту и модель проверяет
shef.toolsai уже внутри `LlmService::stream()`**, поэтому их отказ приходит событием
`error` в потоке (коды `quota`, `model`), как и обрыв ответа. Дальше — события, по
одному на строку `data: {json}` (`Chat\StreamEvents`); первой строкой идёт
комментарий SSE `: ping` (окно его пропускает):

| событие | поля | когда |
|---|---|---|
| `start` | `role`, `sources: [{title, url}]` | проверки пройдены, модель позвали |
| `delta` | `text` | кусок ответа, как пришёл |
| `finish` | `finishReason` (`stop`, `length`) | ответ целиком |
| `error` | `code` (`quota`, `model`, `internal`), `message` | квота исчерпана, модель не подключена, не ответила или оборвала ответ, сбой модуля |

Поток кончился без `finish` — окно считает, что ответ оборвался.

**Как сделано.** Потокового ответа контроллера в ядре нет
(`main/lib/engine/response/`: `Json`, `File`, `Zip\Archive`…). Но
`HttpApplication::runController()` отдаёт как есть любой наследник `HttpResponse`,
который вернуло действие, и зовёт его `send()` (`main/lib/httpapplication.php`,
`buildResponse()`; `main/lib/application.php`, `end()`) — так устроены
`Engine\Response\File` и `Zip\Archive`. `Chat\StreamResponse::send()` пишет заголовки
и печатает события по мере прихода. Фильтры действия — штатные: авторизация, POST,
`Csrf`, `CloseSession`. Модель — `LlmService::stream()` shef.toolsai: квота до
вызова, журнал расхода после; закрытая вкладка ответ не обрывает
(`ignore_user_abort`) — журнал дописывается. Время скрипта —
`StreamResponse::TIME_LIMIT` (300 с).

## Оценки ответов (1.1.0)

Под каждым ответом — «👍 Полезно» и «👎 Не помогло» (с комментарием по желанию).
Оценка — действие `rate`: право на чат, роль должна быть открыта сотруднику, не
больше 30 оценок в минуту. Хранится в таблице `shef_toolsaiassistant_feedback`:
сотрудник, роль, чат (`general` или `record:<тип>:<id>`), вопрос (до 1000 знаков),
ответ (до 2000), оценка `1` / `-1`, комментарий (до 1000), время. Вопрос и ответ
присылает окно чата; хранятся `CHAT_feedbackdays` дней (по умолчанию 90), потом
их удаляет агент `FeedbackStore::cleanAgent()`. Список — страница
`/shef/toolsaiassistant/feedback/` ([02-rights.md](02-rights.md)).

## Разбор оценок (1.4.0)

`shef:toolsaiassistant.feedback.<действие>` — POST, авторизация, sessid, право
`feedback.edit` (`Controller\Feedback`):

| действие | параметры | ответ |
|---|---|---|
| `status` | `ids` (до 200), `status` — `reviewed`, `rejected`, `new` | `{updated, status}` |
| `expect` | `id`, `expected` — по факту на строку, до 4000 знаков | `{id, status, expected}` |
| `export` | `ids` (до 200) | `{cases, json, exported, skipped, reasons}`; выгруженные — статус `eval` |

Колонки таблицы с 1.4.0: `STATUS` (`new` / `reviewed` / `eval` / `rejected`),
`EXPECTED`, `REVIEWED_BY`, `REVIEWED_AT`. Формат кейса — [08-feedback.md](08-feedback.md).

`shef:toolsaiassistant.access.save` — сохранение страницы прав (только администратор).
