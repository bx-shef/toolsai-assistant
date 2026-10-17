<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Main;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\ScalarField;

/**
 * Создание и удаление таблиц модуля по описанию ORM-сущности.
 *
 * Таблица — createDbTable() сущности (статья «ORM: создать таблицу»,
 * framework-docs pages/orm/orm-concepts.md): он не меняет уже существующую
 * таблицу, поэтому сначала проверяем, есть ли она. Составной уникальный
 * индекс createDbTable() не строит — его добавляет createIndex() соединения
 * (main/lib/db/mysqlcommonconnection.php, тип Connection::INDEX_UNIQUE).
 *
 * Новые колонки в уже созданной таблице (1.4.0) — addColumns(): createDbTable()
 * их не добавит, а установщик на портале не запускается. Колонка — по полю
 * ORM: тип даёт getColumnTypeByField() помощника SQL ядра
 * (main/lib/db/mysqlisqlhelper.php), обязательной колонке нужен DEFAULT — у
 * старых строк значения нет.
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

	/**
	 * Добавить в таблицу колонки, которых в ней нет (по описанию ORM).
	 *
	 * @param class-string<DataManager> $table
	 * @param list<string> $columns имена полей getMap()
	 * @return list<string> какие колонки добавлены
	 */
	public static function addColumns(string $table, array $columns): array
	{
		$connection = Application::getConnection();
		$name = $table::getTableName();
		if(!$connection->isTableExists($name))
		{
			return [];
		}
		$missing = static::missingColumns(array_keys($connection->getTableFields($name)), $columns);
		$helper = $connection->getSqlHelper();
		$entity = $table::getEntity();
		foreach($missing as $column)
		{
			$field = $entity->getField($column);
			if(!$field instanceof ScalarField)
			{
				continue;
			}
			$sql = 'ALTER TABLE '.$helper->quote($name).' ADD COLUMN '.$helper->quote($column).' '.$helper->getColumnTypeByField($field);
			if($field->isRequired())
			{
				$default = $field->getDefaultValue();
				$sql .= ' NOT NULL'.(is_scalar($default) ? ' DEFAULT '.(is_int($default) ? (string)$default : "'".$helper->forSql((string)$default)."'") : '');
			}
			else
			{
				$sql .= ' NULL';
			}
			$connection->queryExecute($sql);
		}
		if($missing !== [])
		{
			$connection->clearCaches();
		}

		return $missing;
	}

	/**
	 * Каких колонок нет: сравнение без учёта регистра (MySQL отдаёт имена как
	 * создавали, другие базы — бывает, иначе).
	 *
	 * @param list<string> $existing колонки таблицы
	 * @param list<string> $wanted нужные колонки
	 * @return list<string>
	 */
	public static function missingColumns(array $existing, array $wanted): array
	{
		$have = array_fill_keys(array_map('strtoupper', $existing), true);

		return array_values(array_filter($wanted, static fn(string $column): bool => !isset($have[strtoupper($column)])));
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
