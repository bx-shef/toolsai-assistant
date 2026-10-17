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
	/** Страница «Оценки ответов» (1.1.0, Feedback\FeedbackPage). */
	public const FEEDBACK_URL = '/shef/toolsaiassistant/feedback/';
	/**
	 * «Библиотека промптов» shef.toolsai — там правят тексты ролей помощника
	 * (1.5.0, ссылка «Править промпт роли» со страницы оценок). Адрес страницы,
	 * не API: раздел promptlib, Settings\Sections shef.toolsai.
	 */
	public const TOOLSAI_PROMPT_LIBRARY_URL = '/shef/toolsai/?section=promptlib';
	/** Страница возврата из входа в MCP-сервер (1.10.0, режим «Агент»): redirect_uri чата как клиента OAuth. */
	public const MCP_CALLBACK_URL = '/shef/toolsaiassistant/mcp/';
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
