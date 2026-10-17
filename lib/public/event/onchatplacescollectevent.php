<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Public\Event;

use Bitrix\Main\Event;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * shef.toolsaiassistant:OnChatPlacesCollect (1.3.0) — сбор готовых мест для
 * настройки «Где показывать чат».
 *
 * Параметров нет. Обработчик отвечает
 *
 *   new EventResult(EventResult::SUCCESS, ['places' => [
 *       ['id' => 'view', 'title' => 'Вид «ИИ»', 'url' => '/shef/toolsai/view/', 'group' => 'ИИ'],
 *   ]], 'shef.toolsai');
 *
 * Разбор ответа — Panel\PlaceCatalog::normalize(); контракт —
 * docs/guide/04-events.md.
 */
final class OnChatPlacesCollectEvent extends Event
{
	public const TYPE = 'OnChatPlacesCollect';

	public function __construct()
	{
		parent::__construct(Constants::MODULE_ID, self::TYPE);
	}
}
