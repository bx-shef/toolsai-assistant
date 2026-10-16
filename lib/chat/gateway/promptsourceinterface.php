<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

/**
 * Роли и текст для модели — из реестра промптов shef.toolsai.
 * Реализация — ToolsAiPromptSource; в тестах — подмена.
 */
interface PromptSourceInterface
{
	/**
	 * Включённые роли реестра, открытые сотруднику, в порядке реестра.
	 *
	 * @param list<string>|null $allowed коды открытых ролей; null — все
	 * @return list<array{code: string, title: string, description: string}>
	 */
	public function roles(?array $allowed): array;

	/**
	 * Системный текст роли и готовые вопросы для места и сущности.
	 *
	 * @param list<string>|null $allowed
	 * @return array{system: string, examples: list<string>}
	 */
	public function compose(string $roleCode, ?int $entityTypeId, ?array $allowed): array;
}
