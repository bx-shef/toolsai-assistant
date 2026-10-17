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
	public const BUTTON_TEXT = 'ИИ: чат';
	public const WIDTH_MIN = 320;
	public const WIDTH_MAX = 720;
	public const WIDTH_DEFAULT = 400;
	/**
	 * Выше места Марты в шаблоне Air. С 1.3.0 это запасной слой: панель
	 * регистрируется в стопке ZIndexManager ядра и при открытии поднимается
	 * над верхним слайдером (chat.js, watchSliders()).
	 */
	public const Z_INDEX = 990;
	public const TITLE_MAX = 120;
	/** Файлы расширения shef.toolsaiassistant.chat (install/js/.../chat/config.php) — тот же порядок. */
	public const ASSETS = [
		'/bitrix/js/shef/toolsaiassistant/chat/chat.css',
		'/bitrix/js/shef/toolsaiassistant/chat/markdown.js',
		'/bitrix/js/shef/toolsaiassistant/chat/stream.js',
		'/bitrix/js/shef/toolsaiassistant/chat/chat.js',
	];

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
	 * Настройки окна для JS-расширения (BX.Shef.ToolsAiAssistant.Chat.init).
	 *
	 * @param string $context тема общего чата — название страницы, где открыт чат
	 */
	public static function settings(string $context, string $buttonId = self::BUTTON_ID): array
	{
		return [
			'button' => preg_replace('/[^a-zA-Z0-9_-]/', '', $buttonId) ?: self::BUTTON_ID,
			'context' => mb_substr(trim($context), 0, 80),
			'min' => self::WIDTH_MIN,
			'max' => self::WIDTH_MAX,
			'def' => self::WIDTH_DEFAULT,
			'z' => self::Z_INDEX,
			// Файлы расширения — для страницы в слайдере: она подгружает их в верхнее окно (1.3.0)
			'assets' => self::ASSETS,
		];
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
