<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

use Bitrix\Main\Type\DateTime;
use Shef\ToolsAiAssistant\Chat\Gateway\ChoiceStoreInterface;
use Shef\ToolsAiAssistant\Chat\Model\RoleChoiceTable;
use Shef\ToolsAiAssistant\Main\TableInstaller;

/**
 * Запомненная роль на сотрудника и чат — таблица Model\RoleChoiceTable.
 */
final class RoleChoiceStore implements ChoiceStoreInterface
{
	/** Создать таблицу (установка). */
	public static function install(): void
	{
		TableInstaller::install(RoleChoiceTable::class, RoleChoiceTable::INDEXES);
	}

	/** Удалить таблицу (удаление без «сохранить данные»). */
	public static function uninstall(): void
	{
		TableInstaller::uninstall(RoleChoiceTable::class);
	}

	public function get(int $userId, string $chatKey): ?string
	{
		$row = RoleChoiceTable::getList([
			'select' => ['ROLE_CODE'],
			'filter' => ['=USER_ID' => $userId, '=CHAT_KEY' => $chatKey],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? (string)$row['ROLE_CODE'] : null;
	}

	public function save(int $userId, string $chatKey, string $roleCode): void
	{
		$this->write($userId, $chatKey, $roleCode, true);
	}

	/** $retry — один повтор после гонки вставки; второй сбой — наружу, без бесконечной рекурсии. */
	private function write(int $userId, string $chatKey, string $roleCode, bool $retry): void
	{
		if($userId <= 0)
		{
			return;
		}
		$row = RoleChoiceTable::getList([
			'select' => ['ID', 'ROLE_CODE'],
			'filter' => ['=USER_ID' => $userId, '=CHAT_KEY' => $chatKey],
			'limit' => 1,
		])->fetch();
		if(is_array($row))
		{
			if($row['ROLE_CODE'] !== $roleCode)
			{
				RoleChoiceTable::update($row['ID'], ['ROLE_CODE' => $roleCode, 'CHANGED_AT' => new DateTime()]);
			}

			return;
		}
		try
		{
			RoleChoiceTable::add(['USER_ID' => $userId, 'CHAT_KEY' => $chatKey, 'ROLE_CODE' => $roleCode, 'CHANGED_AT' => new DateTime()]);
		}
		catch(\Bitrix\Main\DB\SqlQueryException $exception)
		{
			// Две вкладки выбрали роль одновременно: строку уже вставила другая — уникальный индекс
			if(!$retry)
			{
				throw $exception;
			}
			$this->write($userId, $chatKey, $roleCode, false);
		}
	}
}
