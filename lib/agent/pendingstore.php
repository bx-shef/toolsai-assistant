<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Agent;

use Bitrix\Main\Application;
use Shef\ToolsAiAssistant\Agent\Model\PendingTable;
use Shef\ToolsAiAssistant\Main\TableInstaller;

/**
 * Ждущие вызовы в таблице Model\PendingTable. «Забрать» атомарно: DELETE по
 * ID и проверка, что удалили именно мы (getAffectedRowsCount() = 1) — два
 * одновременных «Выполнить» (две вкладки) не выполнят запись дважды.
 * Старше TTL — не выдаются и подчищаются при записи.
 */
final class PendingStore implements PendingStoreInterface
{
	public const TTL = 1800;
	public const MAX = 5;

	public static function install(): void
	{
		TableInstaller::install(PendingTable::class, PendingTable::INDEXES);
	}

	public static function uninstall(): void
	{
		TableInstaller::uninstall(PendingTable::class);
	}

	public function put(int $userId, string $id, array $call): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$table = $helper->quote(PendingTable::getTableName());
		$connection->queryExecute('DELETE FROM '.$table.' WHERE CREATED_AT < '.((int)$call['at'] - self::TTL));
		PendingTable::add(['USER_ID' => $userId, 'CODE' => $id, 'PAYLOAD' => (string)json_encode($call, JSON_UNESCAPED_UNICODE), 'CREATED_AT' => (int)$call['at']]);
		// Не больше MAX на сотрудника: старые — прочь
		$rows = PendingTable::getList(['select' => ['ID'], 'filter' => ['=USER_ID' => $userId], 'order' => ['ID' => 'DESC'], 'offset' => self::MAX]);
		while($row = $rows->fetch())
		{
			PendingTable::delete($row['ID']);
		}
	}

	public function take(int $userId, string $id): ?array
	{
		$row = PendingTable::getList(['filter' => ['=USER_ID' => $userId, '=CODE' => $id], 'limit' => 1])->fetch();
		if(!is_array($row))
		{
			return null;
		}
		$connection = Application::getConnection();
		$connection->queryExecute('DELETE FROM '.$connection->getSqlHelper()->quote(PendingTable::getTableName()).' WHERE ID = '.(int)$row['ID']);
		if($connection->getAffectedRowsCount() !== 1 || time() - (int)$row['CREATED_AT'] > self::TTL)
		{
			return null;
		}
		$call = json_decode((string)$row['PAYLOAD'], true);

		return is_array($call) ? $call : null;
	}
}
