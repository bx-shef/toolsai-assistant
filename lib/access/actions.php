<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Access;

/**
 * Права модуля, которые раздаются ролями доступа (страница «Права доступа»).
 *
 *   chat.use      — пользоваться чатом: кнопка «✦ ИИ: чат», панель справа,
 *                   действие строки «✦ Спросить ИИ»;
 *   feedback.view — смотреть оценки ответов (страница «Оценки ответов», 1.1.0);
 *   agent.use     — режим «Агент» (1.10.0): чат вызывает инструменты MCP-сервера
 *                   toolsai-mcp от имени сотрудника; тянет за собой chat.use;
 *   feedback.edit — разбирать оценки: статус, «что должно было быть»,
 *                   выгрузка кейсов проверки промптов (1.5.0); тянет за
 *                   собой feedback.view.
 *
 * Какие роли помощника открыты сотруднику, с 1.6.0 решает библиотека
 * промптов shef.toolsai 1.54.0 («Кому доступна» у роли), а не эта страница.
 * Старые записи прав role:<код> (до 1.6.0) не читаются — normalize()
 * отбрасывает неизвестные коды — и удаляются при обновлении
 * (Main\Maintenance, шаг rights → RoleStore::purgeLegacy()). Переноса в
 * «Кому доступна» нет — решение владельца: после обновления роли открыты
 * всем с chat.use, пока администратор не закроет их в библиотеке (см.
 * CHANGELOG 1.6.0 и docs/guide/02-rights.md).
 *
 * Чистый класс — без ядра (access_test.php).
 */
final class Actions
{
	public const CHAT_USE = 'chat.use';
	/** Смотреть оценки ответов «полезно / не помогло» (1.1.0). */
	public const FEEDBACK_VIEW = 'feedback.view';
	/** Разбирать оценки: статус, ожидаемый ответ, выгрузка кейсов проверки (1.5.0). */
	public const FEEDBACK_EDIT = 'feedback.edit';
	/** Режим «Агент»: работать с порталом через инструменты MCP (1.10.0). */
	public const AGENT_USE = 'agent.use';
	/** Приставка прав на роли до 1.6.0 (role:<код промпта>) — только для чистки. */
	public const LEGACY_ROLE_PREFIX = 'role:';

	/** Код промпта реестра shef.toolsai — тот же шаблон, что Prompt\Definition::fromArray() (оценки, фильтр грида). */
	public const PROMPT_CODE_PATTERN = '/^[a-z0-9_.]+:[a-z0-9_.\-]{1,80}$/';

	/** Группа прав — раздел страницы прав. */
	public const GROUP_CHAT = 'chat';
	public const GROUPS = [
		self::GROUP_CHAT => 'Чат ИИ-помощника',
	];

	/**
	 * Права модуля.
	 *
	 * @return array<string, array{title: string, group: string, hint: string}>
	 */
	public static function getList(): array
	{
		return [
			self::CHAT_USE => [
				'title' => 'Пользоваться чатом',
				'group' => self::GROUP_CHAT,
				'hint' => 'Кнопка «✦ ИИ: чат», панель справа и «✦ Спросить ИИ» в строках списка. Каждый ответ тратит месячную квоту shef.toolsai. Какие роли помощника открыты сотруднику — задаётся в «Библиотеке промптов» shef.toolsai («Кому доступна» у роли).',
			],
			self::AGENT_USE => [
				'title' => 'Работать с порталом через MCP (режим «Агент»)',
				'group' => self::GROUP_CHAT,
				'hint' => 'Помощник в чате ищет и читает данные портала и по кнопке «Выполнить» меняет их — инструментами MCP-сервера toolsai-mcp от имени сотрудника, в рамках его прав в Битрикс24. Какие инструменты доступны и кому можно менять данные, решает ещё и администратор MCP-сервера. Даёт и право пользоваться чатом.',
			],
			self::FEEDBACK_VIEW => [
				'title' => 'Смотреть оценки ответов',
				'group' => self::GROUP_CHAT,
				'hint' => 'Страница «Оценки ответов»: кто и в какой роли отметил ответ «полезно» или «не помогло», вопрос и ответ кратко, комментарий. В вопросах и ответах бывают фрагменты записей CRM — давайте тем, кому их можно видеть. Администратору портала открыта всегда.',
			],
			self::FEEDBACK_EDIT => [
				'title' => 'Разбирать оценки ответов',
				'group' => self::GROUP_CHAT,
				'hint' => 'На странице «Оценки ответов»: ставить статус («разобрана», «отклонена»), писать, что должно было быть в ответе, и выгружать оценки кейсами проверки промптов shef.toolsai. Даёт и право смотреть оценки. Администратору портала открыто всегда.',
			],
		];
	}

	/** Известное право. */
	public static function exists(string $action): bool
	{
		return isset(static::getList()[$action]);
	}

	/**
	 * Оставить известные права и добавить то, что они тянут за собой
	 * (feedback.edit — feedback.view). Порядок — как в getList().
	 *
	 * @param iterable<mixed> $codes
	 * @return list<string>
	 */
	public static function normalize(iterable $codes): array
	{
		$fixed = [];
		foreach($codes as $code)
		{
			if(!is_string($code) || !static::exists($code))
			{
				continue;
			}
			$fixed[$code] = true;
			if($code === self::FEEDBACK_EDIT)
			{
				$fixed[self::FEEDBACK_VIEW] = true;
			}
			if($code === self::AGENT_USE)
			{
				$fixed[self::CHAT_USE] = true;
			}
		}

		return array_values(array_filter(array_keys(static::getList()), static fn(string $code): bool => isset($fixed[$code])));
	}
}
