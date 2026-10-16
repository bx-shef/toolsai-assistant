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
 * Только публичная часть, обычный хит (не ajax, не страница во фрейме
 * слайдера), авторизованный сотрудник с правом «Пользоваться чатом». Кнопка
 * попадает в ui.toolbar: шаблон портала выводит его отложенным буфером
 * (ui/install/components/bitrix/ui.toolbar/class.php, addBufferContent), так
 * что кнопку можно добавить и здесь, до кода страницы.
 *
 * Legacy-событие без параметров: регистрируется как compatible
 * (installEvents в .settings.php).
 */
final class OnPrologEventHandler
{
	public static function handle(): void
	{
		try
		{
			if(defined('ADMIN_SECTION') && ADMIN_SECTION === true)
			{
				return;
			}
			$request = Application::getInstance()->getContext()->getRequest();
			if($request->isAjaxRequest() || $request->get('IFRAME') === 'Y')
			{
				return;
			}
			$config = Config::current();
			if(!$config->enabled || !$config->matchesPath((string)parse_url((string)$request->getRequestUri(), PHP_URL_PATH)) || !ChatPanel::canUse())
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
