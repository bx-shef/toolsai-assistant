# 06. API для разработчиков

> Последняя сверка: 2026-10-16

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
| `answer` | `messages: [{role: user\|assistant, text}]`, `record`, `role` | `{text, role, sources: [{title, url}], finishReason}` |

Коды ошибок (`errors[0].code`): `access`, `role`, `no_roles`, `empty_question`,
`quota`, `rate`, `record`, `model`, `internal`. Текст ошибки можно показывать сотруднику.

`shef:toolsaiassistant.access.save` — сохранение страницы прав (только администратор).
