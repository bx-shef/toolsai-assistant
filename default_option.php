<?php
/**
 * Значения настроек по умолчанию — их отдаёт Option::get(), пока настройку не
 * сохранили. Совпадают с умолчаниями Shef\ToolsAiAssistant\Config.
 */
$shef_toolsaiassistant_default_option = [
	'PANEL_enabled' => 'Y',
	'PANEL_paths' => "/crm/\n/shef/toolsai/view/",
	'CHAT_history' => '10',
	'CHAT_kblimit' => '5',
	'CHAT_ratelimit' => '10',
	'CHAT_feedbackdays' => '90',
	'AGENT_enabled' => 'N',
	'AGENT_mcpurl' => '',
	'AGENT_steps' => '6',
	'AGENT_autoread' => 'Y',
	'AGENT_resultmax' => '8000',
];
