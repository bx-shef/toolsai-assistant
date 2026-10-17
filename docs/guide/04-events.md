# 04. События

> Последняя сверка: 2026-10-17

Постоянные подписки — в `installEvents` (`.settings.php`): установщик
регистрирует их, удаление снимает те же (`tests/install_test.php`).

| событие | обработчик | что делает |
|---|---|---|
| `shef.toolsai:OnPromptRegistryBuild` (D7) | `Internals\Integration\ToolsAi\EventHandler\OnPromptRegistryBuildEventHandler::handle(Event): EventResult` | отдаёт шесть ролей и типовые запросы меню промптов (1.7.0): `EventResult(SUCCESS, ['prompts' => [...Roles::raw(), ...TypicalPrompts::raw()]], 'shef.toolsaiassistant')` |
| `shef.toolsai:OnPromptPlacesCollect` (D7, 1.54.0; у нас 1.7.0) | `Internals\Integration\ToolsAi\EventHandler\OnPromptPlacesCollectEventHandler::handle(Event): EventResult` | места чата для «Где доступен» в библиотеке промптов: `chat`, `chat.list`, `chat.card` (`Prompt\ChatPlaces`); в навыке хранятся как `shef.toolsaiassistant:<место>` |
| `shef.toolsai:OnViewRowActionsBuild` (D7, 1.50.0) | `Internals\Integration\ToolsAi\EventHandler\OnViewRowActionsBuildEventHandler::handle(Event): EventResult` | пункт «✦ Спросить ИИ» в меню строки вида «ИИ»: `['actions' => [['text', 'onclick']]]`, только при праве на чат |
| `shef.toolsai:OnViewToolbarBuild` (D7, 1.53.0; у нас 1.3.1) | `Internals\Integration\ToolsAi\EventHandler\OnViewToolbarBuildEventHandler::handle(Event): EventResult` | кнопка «✦ ИИ: чат» в шапке вида «ИИ»: `['buttons' => [['text', 'onclick', 'id']]]` — если чат включён, вид входит в «Где показывать чат» и у сотрудника есть право. Вид ставит её штатным Toolbar API с иконкой свёрнутого режима. Без shef.toolsai 1.53 событие не приходит — кнопку ставит `OnProlog`, как раньше |
| `shef.toolsai:OnViewSettingsMenuBuild` (D7, 1.53.0; у нас 1.5.1) | `Internals\Integration\ToolsAi\EventHandler\OnViewSettingsMenuBuildEventHandler::handle(Event): EventResult` | пункты в меню шестерёнки вида «ИИ»: `['items' => [['text', 'url']]]` — «ИИ чат с ролями: настройки» (право на модуль от R) и «Оценки ответов» (право `feedback.view`). Без shef.toolsai 1.53 событие не приходит |
| `main:OnProlog` (compatible, без параметров) | `Internals\Integration\Main\EventHandler\OnPrologEventHandler::handle()` | на страницах из настройки «Где показывать чат» — кнопка «✦ ИИ: чат» (Toolbar API на PHP, `dataset` `toolbar-collapsed-icon`; в виде «ИИ» при событии `OnViewToolbarBuild` — только панель) и панель; страница в слайдере (`IFRAME=Y`) — тоже, чат откроется в верхнем окне (1.3.0) |

Контракт `OnPromptRegistryBuild` задаёт shef.toolsai (`lib/prompt/registryservice.php`,
`RegistryService::defaults()`): коды промптов обязаны начинаться с ID модуля —
чужие реестр отбрасывает. После установки модуль зовёт `PromptService::forget()`,
чтобы реестр перечитался.

`shef.toolsai:OnPromptChanged` модуль не слушает: своего кеша промптов у него нет,
текст роли берётся из реестра на каждом ответе.

## Своё событие: `shef.toolsaiassistant:OnChatWidgetsCollect` (1.7.0)

Виджеты модулей в ответах чата — [09-prompts-widgets.md](09-prompts-widgets.md).
Шлёт `Chat\WidgetCatalog::collect()` (D7, параметров нет), раз за хит: при сборке
подсказки модели и ответе `info` окну. Ответ модуля:
`new EventResult(EventResult::SUCCESS, ['widgets' => [['type' => '…', 'extension' => '…', 'description' => '…']]], '<модуль>')`.

## Своё событие: `shef.toolsaiassistant:OnChatPlacesCollect` (1.3.0)

Сбор готовых мест для настройки «Где показывать чат». Шлёт
`Panel\PlaceCatalog::collect()` — при открытии подбора мест на странице настроек
(провайдер `PlaceProvider`), раз за хит. Класс события —
`Public\Event\OnChatPlacesCollectEvent` (D7, параметров нет).

Ответ обработчика:

```php
new EventResult(EventResult::SUCCESS, ['places' => [
	['id' => 'view', 'title' => 'Вид «ИИ»', 'url' => '/shef/toolsai/view/', 'group' => 'ИИ'],
]], 'shef.toolsai');
```

| поле | что | правило |
|---|---|---|
| `id` | код места в модуле | непустая строка до 100 знаков |
| `title` | название в подборе | непустая строка, обрезается до 100 знаков |
| `url` | адрес места | путь своего портала, можно с параметрами: `/shef/toolsai/view/?type=2` (`Panel\PlaceUrl::normalize()`) |
| `group` | подпись группы | строка, обрезается до 100 знаков; пусто — ID модуля ответа, а без него — `CRM` |

- Учитываются только ответы `EventResult::SUCCESS`; негодное место пропускается
  молча, остальные места ответа остаются (`PlaceCatalog::normalize()`).
- **Ключ места в настройке — адрес.** Повтор адреса — берётся первое: свои места
  модуля идут первыми, потом ответы в порядке `sort` подписок. Сменили адрес
  места — у портала, где его выбрали, он останется «своим адресом» со старым путём.
- Сбой обработчика (исключение) — в лог, подбор работает со своими местами.
- Формат места `{id, title, url, group}` — тот же, что у события
  `shef.toolsaiannounce:OnAnnouncementPlacesCollect`: один обработчик может
  отдавать один список обоим.

### Пример: места shef.toolsai

`.settings.php` shef.toolsai, `installEvents`:

```php
[
	'isCompatible' => false,
	'from' => ['module' => 'shef.toolsaiassistant', 'event' => 'OnChatPlacesCollect'],
	'to' => [
		'module' => 'shef.toolsai',
		'class' => '\\Shef\\ToolsAi\\Internals\\Integration\\ToolsAiAssistant\\EventHandler\\OnChatPlacesCollectEventHandler',
		'function' => 'handle',
		'sort' => 100,
	],
],
```

```php
namespace Shef\ToolsAi\Internals\Integration\ToolsAiAssistant\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class OnChatPlacesCollectEventHandler
{
	public static function handle(Event $event): EventResult
	{
		return new EventResult(EventResult::SUCCESS, ['places' => [
			['id' => 'view', 'title' => 'Вид «ИИ»', 'url' => '/shef/toolsai/view/', 'group' => 'ИИ'],
			['id' => 'settings', 'title' => 'Настройки ИИ', 'url' => '/shef/toolsai/', 'group' => 'ИИ'],
		]], 'shef.toolsai');
	}
}
```

Аргумент — `Bitrix\Main\Event`, а не класс события этого модуля: так обработчику
не нужен этот модуль при загрузке (правило `event.md` навыка
`bitrix-best-practice-core`).

## Свои события агента (1.10.0)

`OnAgentToolsCollect`, `OnBeforeAgentToolCall`, `OnAfterAgentToolCall` и события
окна `Shef.ToolsAiAssistant.Agent:*` — [10-agent.md](10-agent.md), «Участие других модулей».
