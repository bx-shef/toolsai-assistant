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
 * Новые колонки в уже созданной таблице (1.5.0) — addColumns(): createDbTable()
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
		if(!$connection->isTableExists($name) || static::missingColumns(array_keys($connection->getTableFields($name)), $columns) === [])
		{
			return [];
		}
		// Два хита сразу (OnProlog и первая оценка) не должны добавлять колонку дважды:
		// под блокировкой перечитать колонки. Не взяли блокировку — доведёт следующий хит.
		$lock = 'shef_taia_alter_'.$name;
		if(!$connection->lock($lock, 0))
		{
			return [];
		}
		try
		{
			$connection->clearCaches();
			$missing = static::missingColumns(array_keys($connection->getTableFields($name)), $columns);
			$helper = $connection->getSqlHelper();
			$entity = $table::getEntity();
			$parts = [];
			foreach($missing as $column)
			{
				$field = $entity->getField($column);
				if($field instanceof ScalarField)
				{
					$parts[] = static::columnSql($helper->quote($column), $helper->getColumnTypeByField($field), $field->isRequired(), $field->getDefaultValue(), static fn(string $v): string => $helper->forSql($v));
				}
			}
			if($parts !== [])
			{
				// Одним ALTER: таблица перестраивается один раз
				$connection->queryExecute('ALTER TABLE '.$helper->quote($name).' '.implode(', ', $parts));
				$connection->clearCaches();
			}

			return $parts !== [] ? $missing : [];
		}
		finally
		{
			$connection->unlock($lock);
		}
	}

	/**
	 * Часть ALTER TABLE для одной колонки: обязательной — NOT NULL и DEFAULT
	 * (у старых строк значения нет), прочей — NULL.
	 *
	 * @param \Closure(string): string $escape экранирование строки (forSql помощника)
	 */
	public static function columnSql(string $quotedName, string $type, bool $required, mixed $default, \Closure $escape): string
	{
		$sql = 'ADD COLUMN '.$quotedName.' '.$type;
		if(!$required)
		{
			return $sql.' NULL';
		}

		return $sql.' NOT NULL'.(is_int($default) ? ' DEFAULT '.$default : (is_string($default) ? " DEFAULT '".$escape($default)."'" : ''));
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
