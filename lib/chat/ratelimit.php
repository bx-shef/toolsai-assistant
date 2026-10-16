<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Ограничение частоты ответов на сотрудника: не больше $limit вызовов модели
 * за скользящую минуту. Каждый ответ стоит денег из общей квоты shef.toolsai —
 * один сотрудник (или зациклившийся скрипт) не должен выбрать её за час.
 *
 * Чистый класс — без ядра (ratelimit_test.php). Хранение отметок —
 * Gateway\RateStoreInterface.
 */
final class RateLimit
{
	public const WINDOW = 60;

	/**
	 * Пустить ли ещё один вызов. $limit = 0 — без ограничения.
	 *
	 * @param list<int> $stamps время прошлых вызовов (unix)
	 * @return array{allowed: bool, stamps: list<int>} отметки в окне — с новой, если пустили
	 */
	public static function hit(array $stamps, int $now, int $limit): array
	{
		$recent = array_values(array_filter(array_map('intval', $stamps), static fn(int $t): bool => $t > $now - self::WINDOW && $t <= $now));
		if($limit > 0 && count($recent) >= $limit)
		{
			return ['allowed' => false, 'stamps' => array_slice($recent, -$limit)];
		}
		$recent[] = $now;

		return ['allowed' => true, 'stamps' => $limit > 0 ? array_slice($recent, -$limit) : []];
	}
}
