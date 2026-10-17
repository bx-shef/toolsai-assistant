<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

use Shef\ToolsAi\Public\Service\PromptService;
use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Chat\RoleIcon;

/**
 * Роли и текст для модели — публичный API shef.toolsai 1.54.0
 * (Public\Service\PromptService, глава docs/guide/32-public-api.md shef.toolsai).
 *
 * Доступ к ролям — «Кому доступна» в библиотеке промптов: roles(),
 * compose() и isAvailable() зовутся для текущего сотрудника
 * (PromptService::USER_CURRENT), сужения со своей стороны нет ($allowed = null).
 *
 * Место — PLACE_ASSISTANT и для общего чата, и для чата по записи: навык
 * подбирается по сущности записи.
 *
 * shef.toolsai старее 1.54.0 (обновили файлы без установщика) — отказ
 * ChatException вместо фатальной ошибки: без «Кому доступна» роли открылись
 * бы всем.
 */
final class ToolsAiPromptSource implements PromptSourceInterface
{
	/** Роли, открытые текущему сотруднику, избранные первыми. */
	public function roles(): array
	{
		static::assertVersion();

		return array_map(static fn(array $role): array => [
			'code' => (string)$role['code'],
			'title' => (string)$role['title'],
			'description' => (string)($role['description'] ?? ''),
			'icon' => RoleIcon::of((string)$role['code'], $role['icon'] ?? null),
			'iconUrl' => RoleIcon::url((string)($role['iconUrl'] ?? '')),
			'group' => mb_substr(trim((string)($role['group'] ?? '')), 0, 60),
			'favorite' => (bool)($role['favorite'] ?? false),
		], PromptService::roles(null, PromptService::USER_CURRENT));
	}

	/** Открыта ли роль текущему сотруднику. */
	public function isAvailable(string $roleCode): bool
	{
		static::assertVersion();

		return PromptService::isAvailable($roleCode, PromptService::USER_CURRENT);
	}

	/** Текст роли (+ её навык для сущности записи), готовые вопросы и шаблон навыка. */
	public function compose(string $roleCode, ?int $entityTypeId): array
	{
		static::assertVersion();
		$composed = PromptService::compose($roleCode, PromptService::PLACE_ASSISTANT, $entityTypeId ?? 0, null, PromptService::USER_CURRENT);
		$template = '';
		if(($composed['type'] ?? '') === 'template' && is_string($composed['skill'] ?? null))
		{
			$template = (string)(PromptService::get($composed['skill'])['text'] ?? '');
		}

		return [
			'system' => (string)$composed['system'],
			'examples' => array_values(array_map('strval', (array)$composed['examples'])),
			'template' => $template,
		];
	}

	/** @throws ChatException shef.toolsai без доступа к ролям из библиотеки (старее 1.54.0) */
	private static function assertVersion(): void
	{
		if(!method_exists(PromptService::class, 'isAvailable'))
		{
			throw new ChatException('Чату нужен shef.toolsai 1.54.0 или новее — обратитесь к администратору портала', ChatException::ACCESS);
		}
	}
}
