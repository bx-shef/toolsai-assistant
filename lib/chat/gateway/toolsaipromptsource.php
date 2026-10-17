<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

use Shef\ToolsAi\Public\Service\PromptService;

/**
 * Роли и текст для модели — публичный API shef.toolsai 1.50.0
 * (Public\Service\PromptService, глава docs/guide/32-public-api.md shef.toolsai).
 *
 * Место — PLACE_ASSISTANT (чат ИИ-помощника) и для общего чата, и для чата по
 * записи: навык подбирается по сущности записи. Навыки shef.toolsai «docs» и
 * «record» с 1.50.0 привязаны к его роли shef.toolsai:assistant и к ролям
 * помощника не подмешиваются — своя фильтрация не нужна.
 */
final class ToolsAiPromptSource implements PromptSourceInterface
{
	/** Включённые роли реестра, открытые сотруднику ($allowed; null — все). */
	public function roles(?array $allowed): array
	{
		return array_map(static fn(array $role): array => [
			'code' => (string)$role['code'],
			'title' => (string)$role['title'],
			'description' => (string)($role['description'] ?? ''),
			// Необязательные поля реестра (1.4.0): иконка ui.icon-set и группа для диалога ролей
			'icon' => \Shef\ToolsAiAssistant\Chat\RoleIcon::of((string)$role['code'], $role['icon'] ?? null),
			'group' => mb_substr(trim((string)($role['group'] ?? '')), 0, 60),
		], PromptService::roles($allowed));
	}

	/** Текст роли (+ её навык для сущности записи) и готовые вопросы. */
	public function compose(string $roleCode, ?int $entityTypeId, ?array $allowed): array
	{
		$composed = PromptService::compose($roleCode, PromptService::PLACE_ASSISTANT, $entityTypeId ?? 0, $allowed);

		return [
			'system' => (string)$composed['system'],
			'examples' => array_values(array_map('strval', (array)$composed['examples'])),
		];
	}
}
