<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

/**
 * Роли и текст для модели — из библиотеки промптов shef.toolsai.
 * Реализация — ToolsAiPromptSource; в тестах — подмена.
 *
 * С 1.6.0 доступ к ролям решает библиотека («Кому доступна» у роли,
 * shef.toolsai 1.54.0): все методы работают для текущего сотрудника.
 */
interface PromptSourceInterface
{
	/**
	 * Роли, открытые текущему сотруднику, без скрытых им, избранные первыми.
	 *
	 * @return list<array{code: string, title: string, description: string, icon: string, iconUrl: string, group: string, favorite: bool}>
	 */
	public function roles(): array;

	/** Открыта ли роль текущему сотруднику: есть, включена, «Кому доступна» пускает. */
	public function isAvailable(string $roleCode): bool;

	/**
	 * Системный текст роли (+ навык для сущности записи), готовые вопросы и
	 * шаблон: если навык «с уточнениями» (type = template) — его текст для
	 * поля ввода, иначе пусто.
	 *
	 * @return array{system: string, examples: list<string>, template: string}
	 */
	public function compose(string $roleCode, ?int $entityTypeId): array;
}
