<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Иконка роли (1.4.0) — класс штатного набора ui.icon-set:
 * «ui-icon-set --<icon>».
 *
 * Библиотека промптов shef.toolsai 1.54.0 отдаёт icon вида set:<имя> (имя
 * из набора ui.icon-set.main) или file:<ID> — тогда картинка приходит в
 * iconUrl (url()), а здесь остаётся иконка по коду роли как запасная. Нет
 * иконки или мусор — подбираем по коду роли (outline).
 *
 * Чистый класс — без ядра (chat_test.php).
 */
final class RoleIcon
{
	public const DEFAULT = 'o-copilot';
	/** Часть кода роли → иконка; первое совпадение. */
	public const BY_CODE = [
		'supervisor' => 'o-crown',
		'manager' => 'o-person',
		'deal.quality' => 'o-check-list',
		'quality' => 'o-check-list',
		'bottleneck' => 'o-bottleneck',
		'coach' => 'o-idea-lamp',
		'customer' => 'o-client-chat',
		'docs' => 'o-knowledge-base',
		'assistant' => 'o-ai-stars',
		'analy' => 'o-graphs-diagram',
		'sale' => 'o-handshake',
	];
	private const NAME = '/^[a-z][a-z0-9-]{1,60}$/';

	/** Имя иконки ui.icon-set: из библиотеки (set:<имя> или имя) или по коду роли. */
	public static function of(string $code, mixed $given = null): string
	{
		if(is_string($given))
		{
			$name = str_starts_with($given, 'set:') ? substr($given, 4) : $given;
			if(preg_match(self::NAME, $name) === 1)
			{
				return $name;
			}
		}
		$lower = strtolower($code);
		foreach(self::BY_CODE as $part => $icon)
		{
			if(str_contains($lower, $part))
			{
				return $icon;
			}
		}

		return self::DEFAULT;
	}

	/**
	 * Адрес картинки роли (PromptService::iconUrl): путь на портале
	 * («/upload/…») или https-адрес облачного хранилища; кавычки, пробелы,
	 * «//хост» и прочие схемы — пусто.
	 */
	public static function url(string $url): string
	{
		return preg_match('~^(/(?!/)|https://)[^\s"\'<>\\\\()]{1,500}$~', $url) === 1 ? $url : '';
	}
}
