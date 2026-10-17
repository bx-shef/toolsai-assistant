<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Public\Service;

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\UI\Extension;
use Shef\ToolsAiAssistant\Access\UserAccess;
use Shef\ToolsAiAssistant\Main\Constants;
use Shef\ToolsAiAssistant\Panel\SidePanel;

/**
 * Публичный API панели чата для других модулей и страниц портала.
 *
 * Свой список CRM (например, вид «ИИ» shef.toolsai) встраивает чат так:
 *
 *   if(Loader::includeModule('shef.toolsaiassistant') && ChatPanel::canUse())
 *   {
 *       ChatPanel::addToolbarButton();                     // «✦ ИИ: чат»
 *       ChatPanel::load('Сделки');                         // панель на странице
 *       $actions[] = ['text' => ChatPanel::ROW_ACTION_TEXT, 'onclick' => ChatPanel::openRecordJs(2, $id, $title)];
 *   }
 *
 * На страницах из настройки «Где показывать чат» модуль делает первые два
 * шага сам (Internals\Integration\Main\EventHandler\OnPrologEventHandler).
 * В JS: window.ShefAiChat.open({entityTypeId, id, title}) и
 * window.ShefAiChat.close().
 */
final class ChatPanel
{
	/** Текст действия строки списка. */
	public const ROW_ACTION_TEXT = '✦ Спросить ИИ';

	/** Открыт ли чат текущему сотруднику (право «Пользоваться чатом»). */
	public static function canUse(): bool
	{
		return UserAccess::current()->canUseChat();
	}

	/** JS для onclick: открыть чат по записи CRM. Данные экранированы. */
	public static function openRecordJs(int $entityTypeId, int $id, string $title): string
	{
		return SidePanel::openRecordJs($entityTypeId, $id, $title);
	}

	/**
	 * Подключить панель на страницу: расширение окна чата и его запуск. Право
	 * проверяет вызывающий (canUse()). Повторный вызов на той же странице
	 * безвреден: панель создаётся один раз.
	 */
	public static function load(string $context = ''): void
	{
		Extension::load(Constants::EXTENSION_CHAT);
		$assets = SidePanel::assets(Extension::getConfig(Constants::EXTENSION_CHAT), static fn(string $file): string => (string)\CUtil::GetAdditionalFileURL($file));
		Asset::getInstance()->addString(SidePanel::initScript(SidePanel::settings($context, SidePanel::BUTTON_ID, $assets)));
	}

	/**
	 * Кнопка «✦ ИИ: чат» в верхней панели страницы — штатно, Toolbar API на
	 * PHP, с иконкой свёрнутого режима (dataset toolbar-collapsed-icon, 1.3.1).
	 */
	public static function addToolbarButton(): void
	{
		if(!Loader::includeModule('ui') || !class_exists(\Bitrix\UI\Toolbar\Facade\Toolbar::class))
		{
			return;
		}
		$button = new \Bitrix\UI\Buttons\Button([
			'text' => SidePanel::BUTTON_TEXT,
			'color' => \Bitrix\UI\Buttons\Color::LIGHT_BORDER,
			'dataset' => ['toolbar-collapsed-icon' => defined(\Bitrix\UI\Buttons\Icon::class.'::AI') ? \Bitrix\UI\Buttons\Icon::AI : SidePanel::COLLAPSED_ICON],
		]);
		$button->addAttribute('id', SidePanel::BUTTON_ID);
		\Bitrix\UI\Toolbar\Facade\Toolbar::addButton($button);
	}
}
