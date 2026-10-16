<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Main;

/**
 * Постоянные модуля: идентификатор, адреса, категория журнала расхода.
 *
 * Чистый класс — без ядра.
 */
final class Constants
{
	public const MODULE_ID = 'shef.toolsaiassistant';
	/** Модуль, который даёт модель, квоту, журнал расхода, реестр промптов и историю записи. */
	public const TOOLSAI_MODULE_ID = 'shef.toolsai';
	/** Мягкая зависимость: база знаний на модуле note. */
	public const KB_MODULE_ID = 'shef.toolsaikb';
	/** Публичный сервис поиска статей shef.toolsaikb (контракт линейки). */
	public const KB_SEARCH_CLASS = '\\Shef\\ToolsAiKb\\Public\\Service\\KbSearchService';

	/** Страница «Права доступа» (публичная часть, install/public). */
	public const RIGHTS_URL = '/shef/toolsaiassistant/rights/';
	/** Компонент страницы прав: ui.accessrights.v2 сохраняет через его ajax.php. */
	public const RIGHTS_COMPONENT = 'shef:toolsaiassistant.rights';

	/** JS-расширение окна чата: /bitrix/js/shef/toolsaiassistant/chat/. */
	public const EXTENSION_CHAT = 'shef.toolsaiassistant.chat';

	/**
	 * Категория в журнале расхода shef.toolsai: строки чата помощника видно
	 * отдельно от остальных сценариев на странице «ИИ: расход и остаток».
	 */
	public const LEDGER_CATEGORY = 'assistant';

	/** Роль по умолчанию — «Помощник менеджера» (Prompt\Roles). */
	public const DEFAULT_ROLE = 'shef.toolsaiassistant:manager';
}
