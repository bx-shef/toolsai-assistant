<?php

/**
 * Возврат из входа в MCP-сервер (1.10.0, режим «Агент» чата помощника):
 * redirect_uri чата как клиента OAuth. Код меняется на токены сотрудника,
 * окно сообщает чату итог и закрывается (Agent\McpCallbackPage). Копируется
 * установщиком (installDir), удаляется при удалении модуля.
 */

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

if(\Bitrix\Main\Loader::includeModule('shef.toolsaiassistant'))
{
	\Shef\ToolsAiAssistant\Agent\McpCallbackPage::show();
}
