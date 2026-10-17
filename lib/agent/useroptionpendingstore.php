<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Agent;

/**
 * Ждущие вызовы — личная опция сотрудника (CUserOptions): до MAX штук,
 * старше TTL — выбрасываются.
 */
final class UserOptionPendingStore implements PendingStoreInterface
{
	public const MAX = 5;
	public const TTL = 1800;
	private const CATEGORY = 'shef.toolsaiassistant';
	private const NAME = 'agent_pending';

	public function put(int $userId, string $id, array $call): void
	{
		$list = static::prune($this->load($userId), (int)$call['at']);
		$list[$id] = $call;
		$list = array_slice($list, -self::MAX, null, true);
		\CUserOptions::SetOption(self::CATEGORY, self::NAME, $list, false, $userId);
	}

	public function take(int $userId, string $id): ?array
	{
		$list = $this->load($userId);
		$call = $list[$id] ?? null;
		unset($list[$id]);
		\CUserOptions::SetOption(self::CATEGORY, self::NAME, static::prune($list, time()), false, $userId);

		return is_array($call) && time() - (int)($call['at'] ?? 0) <= self::TTL ? $call : null;
	}

	/** Без устаревших. */
	public static function prune(array $list, int $now): array
	{
		return array_filter($list, static fn($call): bool => is_array($call) && $now - (int)($call['at'] ?? 0) <= self::TTL);
	}

	private function load(int $userId): array
	{
		$list = \CUserOptions::GetOption(self::CATEGORY, self::NAME, [], $userId);

		return is_array($list) ? $list : [];
	}
}
