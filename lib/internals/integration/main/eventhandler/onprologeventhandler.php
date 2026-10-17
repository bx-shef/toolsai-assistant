<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Internals\Integration\Main\EventHandler;

use Bitrix\Main\Application;
use Shef\ToolsAiAssistant\Access\UserAccess;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Container;
use Shef\ToolsAiAssistant\Public\Service\ChatPanel;

/**
 * main:OnProlog — панель чата и кнопка «✦ ИИ: чат» на страницах из настройки
 * «Где показывать чат» (по умолчанию CRM и вид «ИИ» shef.toolsai).
 *
 * Только публичная часть, не ajax (страница в слайдере — да, с 1.3.0: кнопка
 * открывает чат в верхнем окне), авторизованный сотрудник с правом «Пользоваться чатом». Кнопка
 * попадает в ui.toolbar: шаблон портала выводит его отложенным буфером
 * (ui/install/components/bitrix/ui.toolbar/class.php, addBufferContent), так
 * что кнопку можно добавить и здесь, до кода страницы.
 *
 * Legacy-событие без параметров: регистрируется как compatible
 * (installEvents в .settings.php).
 */
final class OnPrologEventHandler
{
	/** Добавить кнопку и панель, если страница, хит и права подходят; сбой — в лог, страница не падает. */
	public static function handle(): void
	{
		// Обновили файлами без установщика — довести файлы, таблицы, подписки (1.1.0)
		\Shef\ToolsAiAssistant\Main\Maintenance::ensureOnce();
		try
		{
			if(defined('ADMIN_SECTION') && ADMIN_SECTION === true)
			{
				return;
			}
			$request = Application::getInstance()->getContext()->getRequest();
			// Страница в слайдере (IFRAME=Y) — тоже: чат откроется в верхнем окне (1.3.0, chat.js)
			if($request->isAjaxRequest())
			{
				return;
			}
			$config = Config::current();
			if(!$config->enabled || !$config->matchesPath((string)$request->getRequestUri()) || !ChatPanel::canUse())
			{
				return;
			}
			ChatPanel::addToolbarButton();
			ChatPanel::load();
		}
		catch(\Throwable $throwable)
		{
			// Чат — не повод ронять страницу CRM
			Container::getLogger(self::class)?->error($throwable);
		}
	}
}
