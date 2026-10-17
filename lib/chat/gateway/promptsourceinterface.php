<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

/**
 * Роли и текст для модели — из библиотеки промптов shef.toolsai.
 * Реализация — ToolsAiPromptSource; в тестах — подмена.
 *
 * С 1.6.0 доступ к ролям решает библиотека («Кому доступна» у роли,
 * shef.toolsai 1.54.0). Сотрудник передаётся явно ($userId > 0) — тот же,
 * что у AnswerService. «Текущий» ($USER) не используется: где его нет
 * (агент, консоль, поток), shef.toolsai считал бы «без проверки».
 * $userId <= 0 — отказ ChatException, а не роли всем.
 */
interface PromptSourceInterface
{
	/**
	 * Роли, открытые сотруднику, без скрытых им, избранные первыми.
	 *
	 * @return list<array{code: string, title: string, description: string, icon: string, iconUrl: string, group: string, favorite: bool}>
	 * @throws \Shef\ToolsAiAssistant\Chat\ChatException $userId <= 0
	 */
	public function roles(int $userId): array;

	/**
	 * Открыта ли роль сотруднику: есть, включена, «Кому доступна» пускает.
	 *
	 * @throws \Shef\ToolsAiAssistant\Chat\ChatException $userId <= 0
	 */
	public function isAvailable(string $roleCode, int $userId): bool;

	/**
	 * Системный текст роли (+ навык для сущности записи), готовые вопросы и
	 * шаблон: если навык «с уточнениями» (type = template) — его текст для
	 * поля ввода, иначе пусто. role — какую роль библиотека взяла на самом
	 * деле: если роль выключили, shef.toolsai молча берёт первую включённую,
	 * и вызывающий обязан сверить (null — ни одной).
	 *
	 * @return array{role: ?string, system: string, examples: list<string>, template: string}
	 * @throws \Shef\ToolsAiAssistant\Chat\ChatException $userId <= 0
	 */
	public function compose(string $roleCode, ?int $entityTypeId, int $userId): array;
}
