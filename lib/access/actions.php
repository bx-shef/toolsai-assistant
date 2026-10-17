<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Access;

/**
 * Права модуля, которые раздаются ролями доступа (страница «Права доступа»).
 *
 * Два вида прав:
 *
 *   chat.use           — пользоваться чатом: кнопка «✦ ИИ: чат», панель справа,
 *                        действие строки «✦ Спросить ИИ»;
 *   feedback.view      — смотреть оценки ответов (страница «Оценки ответов», 1.1.0);
 *   role:<код промпта> — какая роль помощника (роль реестра промптов
 *                        shef.toolsai) доступна сотруднику. Продавцу не дают
 *                        «Контроль руководителя» — он её не увидит в
 *                        переключателе, и сервер её не примет.
 *
 * Роль помощника тянет за собой chat.use: открыть роль без чата бессмысленно.
 * Список ролей приходит из реестра промптов (их могут добавлять и другие
 * модули, и администратор в «Библиотеке промптов»), поэтому права ролей не
 * перечислены здесь, а строятся по коду: roleRight().
 *
 * Чистый класс — без ядра (access_test.php).
 */
final class Actions
{
	public const CHAT_USE = 'chat.use';
	/** Смотреть оценки ответов «полезно / не помогло» (1.1.0). */
	public const FEEDBACK_VIEW = 'feedback.view';
	public const ROLE_PREFIX = 'role:';

	/** Код промпта реестра shef.toolsai — тот же шаблон, что Prompt\Definition::fromArray(). */
	public const PROMPT_CODE_PATTERN = '/^[a-z0-9_.]+:[a-z0-9_.\-]{1,80}$/';

	/** Группы прав — разделы страницы прав. */
	public const GROUP_CHAT = 'chat';
	public const GROUP_ROLES = 'roles';
	public const GROUPS = [
		self::GROUP_CHAT => 'Чат ИИ-помощника',
		self::GROUP_ROLES => 'Роли помощника',
	];

	/**
	 * Постоянные права (без ролей).
	 *
	 * @return array<string, array{title: string, group: string, hint: string}>
	 */
	public static function getList(): array
	{
		return [
			self::CHAT_USE => [
				'title' => 'Пользоваться чатом',
				'group' => self::GROUP_CHAT,
				'hint' => 'Кнопка «✦ ИИ: чат», панель справа и «✦ Спросить ИИ» в строках списка. Каждый ответ тратит месячную квоту shef.toolsai. Отвечает только в ролях, открытых ниже.',
			],
			self::FEEDBACK_VIEW => [
				'title' => 'Смотреть оценки ответов',
				'group' => self::GROUP_CHAT,
				'hint' => 'Страница «Оценки ответов»: кто и в какой роли отметил ответ «полезно» или «не помогло», вопрос и ответ кратко, комментарий. Администратору портала открыта всегда.',
			],
		];
	}

	/** Право на роль помощника: role:<код промпта>. */
	public static function roleRight(string $promptCode): string
	{
		return self::ROLE_PREFIX.strtolower($promptCode);
	}

	/** Код промпта из права на роль; не право на роль или мусор — null. */
	public static function roleOf(string $action): ?string
	{
		if(!str_starts_with($action, self::ROLE_PREFIX))
		{
			return null;
		}
		$code = substr($action, strlen(self::ROLE_PREFIX));

		return preg_match(self::PROMPT_CODE_PATTERN, $code) ? $code : null;
	}

	/** Известное право: постоянное или право на роль с правильным кодом. */
	public static function exists(string $action): bool
	{
		return isset(static::getList()[$action]) || static::roleOf($action) !== null;
	}

	/**
	 * Оставить известные права и добавить то, что они тянут за собой
	 * (роль — chat.use). Порядок: постоянные, затем роли в порядке прихода.
	 *
	 * @param iterable<mixed> $codes
	 * @return list<string>
	 */
	public static function normalize(iterable $codes): array
	{
		$fixed = [];
		$roles = [];
		foreach($codes as $code)
		{
			if(!is_string($code) || !static::exists($code))
			{
				continue;
			}
			if(static::roleOf($code) !== null)
			{
				$roles[$code] = true;
				$fixed[self::CHAT_USE] = true;
				continue;
			}
			$fixed[$code] = true;
		}

		return array_merge(
			array_values(array_filter(array_keys(static::getList()), static fn(string $code): bool => isset($fixed[$code]))),
			array_keys($roles),
		);
	}
}
