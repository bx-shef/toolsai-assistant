<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Main;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\ORM\Data\DataManager;

/**
 * Создание и удаление таблиц модуля по описанию ORM-сущности.
 *
 * Таблица — createDbTable() сущности (статья «ORM: создать таблицу»,
 * framework-docs pages/orm/orm-concepts.md): он не меняет уже существующую
 * таблицу, поэтому сначала проверяем, есть ли она. Составной уникальный
 * индекс createDbTable() не строит — его добавляет createIndex() соединения
 * (main/lib/db/mysqlcommonconnection.php, тип Connection::INDEX_UNIQUE).
 */
final class TableInstaller
{
	/**
	 * Создать таблицу, если её нет, и её индексы.
	 *
	 * @param class-string<DataManager> $table
	 * @param array<string, array{columns: list<string>, unique: bool}> $indexes имя индекса => колонки
	 */
	public static function install(string $table, array $indexes = []): void
	{
		$connection = Application::getConnection();
		$name = $table::getTableName();
		if(!$connection->isTableExists($name))
		{
			$table::getEntity()->createDbTable();
		}
		foreach($indexes as $indexName => $index)
		{
			if(!$connection->isIndexExists($name, $index['columns']))
			{
				$connection->createIndex($name, $indexName, $index['columns'], null, $index['unique'] ? Connection::INDEX_UNIQUE : null);
			}
		}
	}

	/** Удалить таблицу, если есть. @param class-string<DataManager> $table */
	public static function uninstall(string $table): void
	{
		$connection = Application::getConnection();
		if($connection->isTableExists($table::getTableName()))
		{
			$connection->dropTable($table::getTableName());
		}
	}
}
