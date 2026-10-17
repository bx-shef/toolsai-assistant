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
 * compose() и isAvailable() зовутся для явного сотрудника ($userId > 0 из
 * AnswerService), сужения со своей стороны нет ($allowed = null).
 * USER_CURRENT не берём: без глобального $USER (агент, консоль, поток)
 * shef.toolsai превратил бы его в USER_ANY — «без проверки». $userId <= 0 —
 * отказ. Администратор портала подчиняется «Кому доступна» так же, как все.
 * Без проверки (USER_ANY) — только titles(): названия ролей для фильтра
 * страницы оценок, где доступ к роли не нужен.
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
	/** Роли, открытые сотруднику, избранные первыми. */
	public function roles(int $userId): array
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
		], PromptService::roles(null, static::user($userId)));
	}

	/**
	 * Названия всех включённых ролей (код => название) без проверки доступа —
	 * для фильтра страницы оценок: там видны оценки по любой роли.
	 *
	 * @return array<string, string>
	 */
	public function titles(): array
	{
		static::assertVersion();
		$titles = [];
		foreach(PromptService::roles(null, PromptService::USER_ANY) as $role)
		{
			$titles[(string)$role['code']] = (string)$role['title'];
		}

		return $titles;
	}

	/** Открыта ли роль сотруднику. */
	public function isAvailable(string $roleCode, int $userId): bool
	{
		static::assertVersion();

		return PromptService::isAvailable($roleCode, static::user($userId));
	}

	/**
	 * Текст роли (+ её навык для сущности записи), готовые вопросы и шаблон
	 * навыка. Шаблон берётся через get(), который доступ не проверяет, —
	 * поэтому навык ещё раз сверяется isAvailable() для сотрудника, а не
	 * держится на том, что compose() его уже отфильтровал.
	 */
	public function compose(string $roleCode, ?int $entityTypeId, int $userId): array
	{
		static::assertVersion();
		$user = static::user($userId);
		$composed = PromptService::compose($roleCode, PromptService::PLACE_ASSISTANT, $entityTypeId ?? 0, null, $user);
		$template = '';
		$skill = $composed['skill'] ?? null;
		if(($composed['type'] ?? '') === 'template' && is_string($skill) && PromptService::isAvailable($skill, $user))
		{
			$template = (string)(PromptService::get($skill)['text'] ?? '');
		}

		return [
			'role' => is_string($composed['role'] ?? null) ? $composed['role'] : null,
			'system' => (string)$composed['system'],
			'examples' => array_values(array_map('strval', (array)$composed['examples'])),
			'template' => $template,
		];
	}

	/**
	 * Сотрудник для PromptService: только явный id > 0.
	 *
	 * @throws ChatException 0 и меньше — «без проверки» в shef.toolsai, не пускаем
	 */
	private static function user(int $userId): int
	{
		if($userId <= 0)
		{
			throw new ChatException('Нет доступа к чату ИИ-помощника — войдите на портал', ChatException::ACCESS);
		}

		return $userId;
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
