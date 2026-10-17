<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Internals\Integration\ToolsAi\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Access\UserAccess;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Обработчик shef.toolsai:OnViewSettingsMenuBuild (1.53.0, у нас 1.5.1) —
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
	public static function handle(Event $event): EventResult
	{
		// Право на модуль и право на оценки — порознь: сбой одного не прячет другой пункт
		try
		{
			$right = (string)($GLOBALS['APPLICATION'] ?? null)?->GetGroupRight(Constants::MODULE_ID);
		}
		catch(\Throwable)
		{
			$right = 'D';
		}
		try
		{
			$canViewFeedback = UserAccess::current()->canViewFeedback();
		}
		catch(\Throwable)
		{
			$canViewFeedback = false;
		}

		return new EventResult(EventResult::SUCCESS, ['items' => static::items($right, $canViewFeedback)], Constants::MODULE_ID);
	}

	/** Страница настроек модуля на языке страницы (LANGUAGE_ID). */
	public static function settingsUrl(): string
	{
		return '/bitrix/admin/settings.php?mid='.Constants::MODULE_ID.'&lang='.(defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru');
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
			$items[] = ['text' => 'ИИ чат с ролями: настройки', 'url' => static::settingsUrl()];
		}
		if($canViewFeedback)
		{
			$items[] = ['text' => 'Оценки ответов', 'url' => Constants::FEEDBACK_URL];
		}

		return $items;
	}
}
