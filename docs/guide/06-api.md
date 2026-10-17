# 06. API для разработчиков

> Последняя сверка: 2026-10-17

## JS

```js
window.ShefAiChat.open({entityTypeId: 2, id: 15, title: 'Окна ПВХ'}); // чат по записи
window.ShefAiChat.open();                                               // общий чат
window.ShefAiChat.close();
```

Есть на страницах, где подключена панель. Только верхнее окно (не фрейм слайдера).

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
`OnViewRowActionsBuild` ([04-events.md](04-events.md)).

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

Все проверки — права, роль, частота, запись CRM — **до** потока: отказ приходит
обычным JSON ajax с кодом ошибки, как у `answer`. Дальше — события, по одному на
строку `data: {json}` (`Chat\StreamEvents`):

| событие | поля | когда |
|---|---|---|
| `start` | `role`, `sources: [{title, url}]` | проверки пройдены, модель позвали |
| `delta` | `text` | кусок ответа, как пришёл |
| `finish` | `finishReason` (`stop`, `length`) | ответ целиком |
| `error` | `code`, `message` | модель не ответила или оборвала ответ |

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
(`ignore_user_abort`) — журнал дописывается.

## Оценки ответов (1.1.0)

Под каждым ответом — «👍 Полезно» и «👎 Не помогло» (с комментарием по желанию).
Оценка — действие `rate`: право на чат, роль должна быть открыта сотруднику, не
больше 30 оценок в минуту. Хранится в таблице `shef_toolsaiassistant_feedback`:
сотрудник, роль, чат (`general` или `record:<тип>:<id>`), вопрос (до 1000 знаков),
ответ (до 2000), оценка `1` / `-1`, комментарий, время. Список — страница
`/shef/toolsaiassistant/feedback/` ([02-rights.md](02-rights.md)).

`shef:toolsaiassistant.access.save` — сохранение страницы прав (только администратор).
