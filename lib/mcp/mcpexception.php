<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Mcp;

/**
 * Отказ MCP-сервера или его OAuth. Текст — для сотрудника (без токенов и
 * ответов сервера); код — для окна чата: AUTH — нужно подключиться заново.
 */
final class McpException extends \RuntimeException
{
	public const AUTH = 'mcp_auth';
	public const NETWORK = 'mcp_network';
	public const SERVER = 'mcp_server';
	public const TOOL = 'mcp_tool';
	public const CONFIG = 'mcp_config';

	public function __construct(string $message, public readonly string $errorCode = self::SERVER)
	{
		parent::__construct($message);
	}
}
