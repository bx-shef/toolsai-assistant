<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Internals\Integration\ToolsAi\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Main\Constants;
use Shef\ToolsAiAssistant\Panel\SidePanel;
use Shef\ToolsAiAssistant\Public\Service\ChatPanel;

/**
 * Обработчик shef.toolsai:OnViewToolbarBuild (1.53.0) — кнопка «✦ ИИ: чат» в
 * шапке вида «ИИ». Вид ставит её штатно (Toolbar::addButton с иконкой
 * свёрнутого режима), а не скрипт поверх шапки: BX.UI.Toolbar перестраивает
 * кнопки и чужую вставку затирал.
 *
 * Контракт — shef.toolsai Public\Event\ViewToolbarBuildEvent: ответ
 * EventResult SUCCESS с ['buttons' => [['text', 'onclick', 'id']]].
 * Типизирован Main\Event: без shef.toolsai 1.53 событие просто не придёт.
 * Кнопка — только если чат включён, вид «ИИ» входит в «Где показывать чат»
 * (там же OnProlog подключает панель) и у сотрудника есть право на чат.
 */
final class OnViewToolbarBuildEventHandler
{
	/** Кнопка «✦ ИИ: чат» для шапки вида; не подходит или сбой — пустой список. */
	public static function handle(Event $event): EventResult
	{
		try
		{
			$buttons = ChatPanel::canUse() ? static::buttons(Config::current(), static::requestUri()) : [];
		}
		catch(\Throwable)
		{
			// Сбой не ломает шапку вида: просто без кнопки
			$buttons = [];
		}

		return new EventResult(EventResult::SUCCESS, ['buttons' => $buttons], Constants::MODULE_ID);
	}

	/**
	 * Кнопки по настройкам и адресу страницы (право проверено). Чистая часть.
	 *
	 * @return list<array{text: string, onclick: string, id: string}>
	 */
	public static function buttons(Config $config, string $requestUri): array
	{
		return $config->enabled && $config->matchesPath($requestUri) ? [SidePanel::viewToolbarButton()] : [];
	}

	private static function requestUri(): string
	{
		return (string)\Bitrix\Main\Application::getInstance()->getContext()->getRequest()->getRequestUri();
	}
}
