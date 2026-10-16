<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

/**
 * Запомненная роль сотрудника в чате. Реализация — Chat\RoleChoiceStore.
 */
interface ChoiceStoreInterface
{
	/** Код роли или null — не выбирал. */
	public function get(int $userId, string $chatKey): ?string;

	/** Запомнить роль сотрудника в чате; одна строка на пару сотрудник + чат. */
	public function save(int $userId, string $chatKey, string $roleCode): void;
}
