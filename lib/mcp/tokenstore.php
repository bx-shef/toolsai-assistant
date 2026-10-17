<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Mcp;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Shef\ToolsAiAssistant\Main\Constants;
use Shef\ToolsAiAssistant\Main\TableInstaller;
use Shef\ToolsAiAssistant\Mcp\Model\McpTokenTable;

/**
 * Подключения сотрудников к MCP в таблице Model\McpTokenTable, токены —
 * через TokenCrypto (ключ — опция SYS_tokenkey).
 */
final class TokenStore implements TokenStoreInterface
{
	public const OPTION_KEY = 'SYS_tokenkey';

	public static function install(): void
	{
		TableInstaller::install(McpTokenTable::class, McpTokenTable::INDEXES);
	}

	public static function uninstall(): void
	{
		TableInstaller::uninstall(McpTokenTable::class);
	}

	public function get(int $userId): ?array
	{
		$row = McpTokenTable::getList(['filter' => ['=USER_ID' => $userId], 'limit' => 1])->fetch();
		if(!is_array($row))
		{
			return null;
		}
		$crypto = static::crypto();
		$access = $crypto->decrypt((string)$row['ACCESS_TOKEN']);
		$refresh = $crypto->decrypt((string)$row['REFRESH_TOKEN']);
		if($access === null || $refresh === null)
		{
			return null;
		}

		return ['server' => (string)$row['SERVER'], 'clientId' => (string)$row['CLIENT_ID'], 'access' => $access, 'refresh' => $refresh, 'expiresAt' => (int)$row['EXPIRES_AT']];
	}

	public function save(int $userId, array $token): void
	{
		$crypto = static::crypto();
		$fields = [
			'SERVER' => $token['server'],
			'CLIENT_ID' => $token['clientId'],
			'ACCESS_TOKEN' => $crypto->encrypt($token['access']),
			'REFRESH_TOKEN' => $crypto->encrypt($token['refresh']),
			'EXPIRES_AT' => $token['expiresAt'],
			'CHANGED_AT' => new DateTime(),
		];
		$row = McpTokenTable::getList(['select' => ['ID'], 'filter' => ['=USER_ID' => $userId], 'limit' => 1])->fetch();
		if(is_array($row))
		{
			McpTokenTable::update($row['ID'], $fields);

			return;
		}
		McpTokenTable::add(['USER_ID' => $userId] + $fields);
	}

	public function delete(int $userId): void
	{
		$rows = McpTokenTable::getList(['select' => ['ID'], 'filter' => ['=USER_ID' => $userId]]);
		while($row = $rows->fetch())
		{
			McpTokenTable::delete($row['ID']);
		}
	}

	private static function crypto(): TokenCrypto
	{
		$key = (string)Option::get(Constants::MODULE_ID, self::OPTION_KEY, '');
		if($key === '')
		{
			$key = TokenCrypto::newKey();
			Option::set(Constants::MODULE_ID, self::OPTION_KEY, $key);
		}

		return new TokenCrypto($key);
	}
}
