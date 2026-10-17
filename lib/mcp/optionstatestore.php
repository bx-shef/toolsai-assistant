<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Mcp;

use Bitrix\Main\Config\Option;
use Shef\ToolsAiAssistant\Main\Constants;

/** Состояние на весь портал — опция модуля SYS_mcp_<ключ> (JSON). */
final class OptionStateStore implements StateStoreInterface
{
	public function get(string $key): ?array
	{
		$value = json_decode((string)Option::get(Constants::MODULE_ID, 'SYS_mcp_'.$key, ''), true);

		return is_array($value) ? $value : null;
	}

	public function set(string $key, ?array $value): void
	{
		if($value === null)
		{
			Option::delete(Constants::MODULE_ID, ['name' => 'SYS_mcp_'.$key]);

			return;
		}
		Option::set(Constants::MODULE_ID, 'SYS_mcp_'.$key, (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
}
