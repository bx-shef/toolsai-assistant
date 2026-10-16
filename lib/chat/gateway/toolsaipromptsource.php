<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

use Shef\ToolsAi\Prompt\Definition;
use Shef\ToolsAi\Prompt\RegistryService;

/**
 * Роли и текст для модели из реестра промптов shef.toolsai (1.48.0+):
 * RegistryService::registry()->roles() и RegistryService::compose().
 *
 * Место для навыка: чат по записи — «list» (чат открывают из списка, вид
 * «ИИ»), общий чат — «docs». Навык берётся, только если он привязан к
 * этой роли (parent = код роли): общие навыки shef.toolsai без роли
 * («Документация модуля», «Запись CRM») написаны про его собственную
 * справку и роли помощника не подходят. Администратор может добавить
 * своей роли навык в «Библиотеке промптов» — он подхватится.
 */
final class ToolsAiPromptSource implements PromptSourceInterface
{
	public function roles(?array $allowed): array
	{
		return array_map(static fn(Definition $role): array => [
			'code' => $role->code,
			'title' => $role->title,
			'description' => $role->description,
		], RegistryService::registry()->roles($allowed));
	}

	public function compose(string $roleCode, ?int $entityTypeId, ?array $allowed): array
	{
		$composed = RegistryService::compose(
			$roleCode,
			$entityTypeId !== null ? Definition::PLACE_LIST : Definition::PLACE_DOCS,
			$entityTypeId ?? 0,
			$allowed
		);
		/** @var Definition|null $role */
		$role = $composed['role'];
		/** @var Definition|null $skill */
		$skill = $composed['skill'];
		if($role === null)
		{
			return ['system' => '', 'examples' => []];
		}
		$own = $skill !== null && $skill->parent === $role->code ? $skill : null;

		return [
			'system' => trim($role->text."\n\n".($own?->text ?? '')),
			'examples' => $own !== null && $own->examples !== [] ? $own->examples : $role->examples,
		];
	}
}
