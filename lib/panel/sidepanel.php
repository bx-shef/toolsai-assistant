<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Panel;

/**
 * Панель чата справа — перенос Assistant\SidePanel shef.toolsai (1.44.0–1.47.0)
 * без смены смысла: фиксированный блок поверх правого края страницы, ширина
 * тянется мышью и запоминается в браузере, «открыт» — тоже; кнопка — любой
 * элемент с id BUTTON_ID (кнопка верхней панели Bitrix\UI\Buttons); чат по
 * записи — window.ShefAiChat.open({entityTypeId, id, title}).
 *
 * Что поменялось при переезде: внутри не фрейм сайта документации (Nuxt), а
 * окно чата JS-расширения модуля (install/js/shef/toolsaiassistant/chat), и
 * ответ — через контроллер модуля, а не endpoint/kb.php shef.toolsai.
 * Место «Марты» (#app__right-panel) по-прежнему не трогаем: наша панель —
 * слоем выше; с 1.3.0 открытый чат — и над слайдерами BX.SidePanel, а со
 * страницы в слайдере (IFRAME=Y) он открывается в верхнем окне.
 *
 * Чистый класс — без ядра (panel_test.php).
 */
final class SidePanel
{
	public const BUTTON_ID = 'shef-ai-chat-btn';
	/** Текст кнопки — со звёздочкой в самом тексте (1.3.1): скрипт кнопку тулбара не правит. */
	public const BUTTON_TEXT = '✦ ИИ: чат';
	/**
	 * Иконка кнопки в свёрнутом тулбаре (data-toolbar-collapsed-icon) —
	 * значение Bitrix\UI\Buttons\Icon::AI (ui/lib/buttons/icon.php); без неё
	 * BX.UI.Toolbar пишет предупреждение в консоль (ui.toolbar, src/toolbar.js).
	 */
	public const COLLAPSED_ICON = 'ui-btn-icon-ai ui-icon-set__scope';
	/** Путь вида «ИИ» shef.toolsai: там кнопку ставит событие OnViewToolbarBuild (1.3.1). */
	public const VIEW_PATH = '/shef/toolsai/view/';
	/** id кнопки в виде «ИИ» — свой, не BUTTON_ID: клик уже в onclick, повторная привязка chat.js не нужна. */
	public const VIEW_BUTTON_ID = 'shef-ai-chat-view-btn';
	/** Кнопка в шапке карточки CRM (1.4.0): чат по этой записи. */
	public const DETAIL_BUTTON_TEXT = '✦ Спросить ИИ';
	public const DETAIL_BUTTON_ID = 'shef-ai-chat-record-btn';
	/** Карточки CRM: /crm/<код>/details/<id>/ → entityTypeId (\CCrmOwnerType). Как DETAIL_TYPES в chat.js. */
	public const DETAIL_TYPES = ['lead' => 1, 'deal' => 2, 'contact' => 3, 'company' => 4, 'quote' => 7];
	public const WIDTH_MIN = 320;
	public const WIDTH_MAX = 720;
	/** Запасная ширина: без layout.js. Обычно ширину считает layout.js — clamp(420, 36% окна, 480) (1.4.0). */
	public const WIDTH_DEFAULT = 440;
	/**
	 * Слой панели «поверх» (поле z настроек окна): шаблон не Air и узкий
	 * экран (1.9.0). Ниже стопки слайдеров и попапов ядра (ZIndexStack,
	 * baseIndex 1000) — слайдер встаёт над чатом. В правой колонке шаблона
	 * Air слой задаёт chat.css.
	 */
	public const Z_INDEX = 990;
	public const TITLE_MAX = 120;

	/** Ширина в допустимых пределах. */
	public static function width(mixed $value): int
	{
		$width = is_numeric($value) ? (int)$value : self::WIDTH_DEFAULT;

		return max(self::WIDTH_MIN, min(self::WIDTH_MAX, $width));
	}

	/**
	 * Открыть чат по записи CRM — JS для onclick (действие строки «✦ Спросить
	 * ИИ»): панель справа с историей записи. Данные экранированы для вставки
	 * в атрибут и в <script>.
	 */
	public static function openRecordJs(int $entityTypeId, int $id, string $title): string
	{
		return 'window.ShefAiChat&&window.ShefAiChat.open('.static::json([
			'entityTypeId' => $entityTypeId,
			'id' => $id,
			'title' => mb_substr($title, 0, self::TITLE_MAX),
		]).')';
	}

	/**
	 * Запись CRM по адресу карточки: /crm/deal/details/5/,
	 * /crm/type/<entityTypeId>/details/ID/; новая (ID 0) и другие адреса — null.
	 * Тот же разбор, что recordFromPath() в chat.js.
	 *
	 * @return array{entityTypeId: int, id: int}|null
	 */
	public static function detailRecord(string $requestUri): ?array
	{
		$path = (string)parse_url($requestUri, PHP_URL_PATH);
		if(!preg_match('~^/crm/(?:type/(\d+)|([a-z]+))/details/(\d+)/~', $path, $m))
		{
			return null;
		}
		$type = $m[1] !== '' ? (int)$m[1] : (self::DETAIL_TYPES[$m[2]] ?? 0);
		$id = (int)$m[3];

		return $type > 0 && $id > 0 ? ['entityTypeId' => $type, 'id' => $id] : null;
	}

	/**
	 * JS кнопки карточки CRM: чат по карточке, открытой в этом окне
	 * (во фрейме слайдера — его адрес; chat.js, openCurrent()). Панели нет
	 * (расширение не успело загрузиться) — уведомление.
	 */
	public static function openCurrentJs(): string
	{
		$text = (string)json_encode('Чат ИИ ещё загружается или недоступен — попробуйте через секунду.', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

		return 'if(window.ShefAiChat&&window.ShefAiChat.openCurrent){window.ShefAiChat.openCurrent();}'
			.'else if(BX.UI&&BX.UI.Notification){BX.UI.Notification.Center.notify({content:'.$text.'});}';
	}

	/**
	 * Настройки окна для JS-расширения (BX.Shef.ToolsAiAssistant.Chat.init).
	 *
	 * @param string $context тема общего чата — название страницы, где открыт чат
	 * @param list<string> $assets адреса файлов расширения с версией (assets());
	 *   берутся только /bitrix/js/…
	 */
	public static function settings(string $context, string $buttonId = self::BUTTON_ID, array $assets = []): array
	{
		return [
			'button' => preg_replace('/[^a-zA-Z0-9_-]/', '', $buttonId) ?: self::BUTTON_ID,
			'context' => mb_substr(trim($context), 0, 80),
			'min' => self::WIDTH_MIN,
			'max' => self::WIDTH_MAX,
			'def' => self::WIDTH_DEFAULT,
			'z' => self::Z_INDEX,
			// Файлы расширения — для страницы в слайдере: она подгружает их в верхнее окно (1.3.0)
			'assets' => array_values(array_filter($assets, static fn($url): bool => is_string($url) && str_starts_with($url, '/bitrix/js/'))),
		];
	}

	/**
	 * Файлы расширения из его config.php (Extension::getConfig()) — css, потом
	 * js, в порядке конфига; $url добавляет версию файла
	 * (CUtil::GetAdditionalFileURL). Список один — config.php расширения.
	 *
	 * @param array{css?: string|list<string>, js?: string|list<string>}|null $config
	 * @param \Closure(string): string $url
	 * @return list<string>
	 */
	public static function assets(?array $config, \Closure $url): array
	{
		$files = [];
		foreach(['css', 'js'] as $kind)
		{
			foreach((array)($config[$kind] ?? []) as $file)
			{
				if(is_string($file) && $file !== '')
				{
					$files[] = $url($file);
				}
			}
		}

		return $files;
	}

	/**
	 * Кнопка «✦ ИИ: чат» для shef.toolsai:OnViewToolbarBuild (1.53.0):
	 * ['text', 'onclick', 'id'] по контракту ViewToolbarBuildEvent. Иконку
	 * свёрнутого режима ставит сам вид.
	 *
	 * @return array{text: string, onclick: string, id: string}
	 */
	public static function viewToolbarButton(): array
	{
		return [
			'text' => self::BUTTON_TEXT,
			'onclick' => 'window.ShefAiChat&&window.ShefAiChat.toggle(null)',
			'id' => self::VIEW_BUTTON_ID,
		];
	}

	/**
	 * Ставить ли кнопку тулбара самим (OnProlog). В виде «ИИ» при shef.toolsai
	 * с событием OnViewToolbarBuild — нет: кнопку ставит вид по событию, а
	 * своя кнопка там терялась при сборке шапки. Без события (toolsai < 1.53) —
	 * как раньше.
	 */
	public static function needsOwnToolbarButton(string $requestUri, bool $viewEventAvailable): bool
	{
		$path = (string)parse_url($requestUri, PHP_URL_PATH);

		return !($viewEventAvailable && str_starts_with($path, self::VIEW_PATH));
	}

	/** Скрипт запуска панели — для Asset::addString(). */
	public static function initScript(array $settings): string
	{
		return '<script>BX.ready(function(){if(BX.Shef&&BX.Shef.ToolsAiAssistant&&BX.Shef.ToolsAiAssistant.Chat){BX.Shef.ToolsAiAssistant.Chat.init('.static::json($settings).');}});</script>';
	}

	/** JSON, безопасный и в атрибуте, и внутри <script>. */
	public static function json(array $data): string
	{
		return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	}
}
