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
 * window.ShefAiChat.close(); режим «Агент» (1.9.0) —
 * window.ShefAiChat.agent({entityTypeId, id, title, question}) (вопрос — в поле ввода)
 * или openAgentJs() ниже.
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

	/** Открыт ли текущему сотруднику режим «Агент» (1.9.0): включён в настройках и право agent.use. */
	public static function canUseAgent(): bool
	{
		return \Shef\ToolsAiAssistant\Config::current()->agentReady() && UserAccess::current()->canUseAgent();
	}

	/**
	 * JS для onclick: открыть чат в режиме «Агент» с вопросом в поле ввода
	 * (1.9.0) — например, кнопка «Разобрать входящие» в своём модуле. Запись —
	 * по желанию (0 — общий чат). Данные экранированы.
	 */
	public static function openAgentJs(string $question, int $entityTypeId = 0, int $id = 0, string $title = ''): string
	{
		$rec = ['question' => mb_substr($question, 0, 2000)];
		if($entityTypeId > 0 && $id > 0)
		{
			$rec += ['entityTypeId' => $entityTypeId, 'id' => $id, 'title' => $title];
		}

		return 'window.ShefAiChat&&window.ShefAiChat.agent('.SidePanel::json($rec).')';
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
			'dataset' => ['toolbar-collapsed-icon' => static::collapsedIcon()],
		]);
		$button->addAttribute('id', SidePanel::BUTTON_ID);
		\Bitrix\UI\Toolbar\Facade\Toolbar::addButton($button);
	}

	/**
	 * Кнопка «✦ Спросить ИИ» в шапке карточки CRM (1.4.0) — тем же штатным
	 * Toolbar API, которым карточка ставит свои кнопки (crm.toolbar,
	 * Toolbar::addButton, ButtonLocation::RIGHT). Ставим в OnProlog, раньше
	 * кода карточки, поэтому кнопка — первая в правом ряду, слева от
	 * шестерёнки и «Документ». Клик — чат по записи из адреса карточки.
	 */
	public static function addRecordToolbarButton(): void
	{
		if(!Loader::includeModule('ui') || !class_exists(\Bitrix\UI\Toolbar\Facade\Toolbar::class))
		{
			return;
		}
		$button = new \Bitrix\UI\Buttons\Button([
			'text' => SidePanel::DETAIL_BUTTON_TEXT,
			'color' => \Bitrix\UI\Buttons\Color::LIGHT_BORDER,
			'dataset' => ['toolbar-collapsed-icon' => static::collapsedIcon()],
			'click' => new \Bitrix\UI\Buttons\JsCode(SidePanel::openCurrentJs()),
		]);
		$button->addAttribute('id', SidePanel::DETAIL_BUTTON_ID);
		$button->addAttribute('title', 'Открыть чат ИИ по этой записи');
		\Bitrix\UI\Toolbar\Facade\Toolbar::addButton($button, \Bitrix\UI\Toolbar\ButtonLocation::RIGHT);
	}

	private static function collapsedIcon(): string
	{
		return defined(\Bitrix\UI\Buttons\Icon::class.'::AI') ? \Bitrix\UI\Buttons\Icon::AI : SidePanel::COLLAPSED_ICON;
	}
}
