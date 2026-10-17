<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Сводка над гридом оценок (1.5.0): доля «полезно» по ролям за период.
 * Подсказывает, какую роль править в «Библиотеке промптов» shef.toolsai:
 * сверху — роли с самой низкой долей; роль с малым числом оценок помечена
 * «мало оценок» — на неё не опираться.
 *
 * Чистый класс — без ядра (feedback_test.php).
 */
final class RoleSummary
{
	/** Меньше оценок у роли — доля не опора. */
	public const MIN_RELIABLE = 5;

	/**
	 * @param list<array{ROLE_CODE: string, RATING: int|string, CNT: int|string}> $groups счёт оценок по роли и оценке
	 * @param array<string, string> $titles код роли => название
	 * @return list<array{code: string, title: string, up: int, down: int, total: int, share: float, reliable: bool, url: string}>
	 */
	public static function build(array $groups, array $titles): array
	{
		$byRole = [];
		foreach($groups as $group)
		{
			$code = (string)($group['ROLE_CODE'] ?? '');
			if($code === '')
			{
				continue;
			}
			$byRole[$code] ??= ['up' => 0, 'down' => 0];
			$byRole[$code][(int)$group['RATING'] === Rating::UP ? 'up' : 'down'] += (int)$group['CNT'];
		}
		$out = [];
		foreach($byRole as $code => $count)
		{
			$total = $count['up'] + $count['down'];
			$out[] = [
				'code' => $code,
				'title' => $titles[$code] ?? $code,
				'up' => $count['up'],
				'down' => $count['down'],
				'total' => $total,
				'share' => $total > 0 ? round($count['up'] * 100 / $total, 1) : 0.0,
				'reliable' => $total >= self::MIN_RELIABLE,
				'url' => static::promptUrl($code),
			];
		}
		usort($out, static fn(array $a, array $b): int => [$b['reliable'], $a['share'], -$a['total']] <=> [$a['reliable'], $b['share'], -$b['total']]);

		return $out;
	}

	/**
	 * «Править промпт роли»: карточка роли в «Библиотеке промптов» shef.toolsai
	 * (/shef/toolsai/?section=promptlib&tab=roles&code=…, settings/promptlibrary.php там).
	 */
	public static function promptUrl(string $code): string
	{
		return Constants::TOOLSAI_PROMPT_LIBRARY_URL.'&'.http_build_query(['tab' => 'roles', 'code' => $code], '', '&', PHP_QUERY_RFC3986);
	}
}
