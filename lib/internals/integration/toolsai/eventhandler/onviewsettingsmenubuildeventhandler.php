<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Internals\Integration\ToolsAi\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Access\UserAccess;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Обработчик shef.toolsai:OnViewSettingsMenuBuild (1.53.0, у нас 1.3.2) —
 * пункты в меню шестерёнки вида «ИИ»: «ИИ чат с ролями: настройки» (право
 * на модуль от R, как у options.php) и «Оценки ответов» (право feedback.view).
 *
 * Контракт — shef.toolsai Public\Event\ViewSettingsMenuBuildEvent: ответ
 * EventResult SUCCESS с ['items' => [['text', 'url']]]. Права проверяет
 * обработчик: вид показывает всё, что вернули. Типизирован Main\Event: без
 * shef.toolsai 1.53 событие просто не придёт. Сбой — пустой список.
 */
final class OnViewSettingsMenuBuildEventHandler
{
	public const SETTINGS_URL = '/bitrix/admin/settings.php?mid='.Constants::MODULE_ID.'&lang=ru';

	public static function handle(Event $event): EventResult
	{
		try
		{
			$right = (string)($GLOBALS['APPLICATION'] ?? null)?->GetGroupRight(Constants::MODULE_ID);
			$items = static::items($right, UserAccess::current()->canViewFeedback());
		}
		catch(\Throwable)
		{
			$items = [];
		}

		return new EventResult(EventResult::SUCCESS, ['items' => $items], Constants::MODULE_ID);
	}

	/**
	 * Пункты по правам. Чистая часть.
	 *
	 * @param string $moduleRight право на модуль (GetGroupRight: D, R, W…)
	 * @return list<array{text: string, url: string}>
	 */
	public static function items(string $moduleRight, bool $canViewFeedback): array
	{
		$items = [];
		if($moduleRight >= 'R')
		{
			$items[] = ['text' => 'ИИ чат с ролями: настройки', 'url' => self::SETTINGS_URL];
		}
		if($canViewFeedback)
		{
			$items[] = ['text' => 'Оценки ответов', 'url' => Constants::FEEDBACK_URL];
		}

		return $items;
	}
}
