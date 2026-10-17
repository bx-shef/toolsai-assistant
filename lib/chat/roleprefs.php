<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Недавние и избранные роли сотрудника (1.4.0) — для диалога «Выбрать роль».
 * Чистые функции над массивом {recent, favorite}; хранит RolePrefsStore
 * (CUserOptions). Это удобство, не права: показываем только роли, открытые
 * сотруднику (filter()), право роли сервер проверяет при выборе и ответе.
 */
final class RolePrefs
{
	public const RECENT_MAX = 5;
	public const FAVORITE_MAX = 30;
	private const CODE = '/^[\w.:-]{1,100}$/';

	/** @return array{recent: list<string>, favorite: list<string>} */
	public static function normalize(mixed $raw): array
	{
		$raw = is_array($raw) ? $raw : [];

		return [
			'recent' => array_slice(static::codes($raw['recent'] ?? []), 0, self::RECENT_MAX),
			'favorite' => array_slice(static::codes($raw['favorite'] ?? []), 0, self::FAVORITE_MAX),
		];
	}

	/** Роль выбрали — в начало недавних. */
	public static function touch(array $prefs, string $code): array
	{
		$prefs = static::normalize($prefs);
		if(preg_match(self::CODE, $code) !== 1)
		{
			return $prefs;
		}
		$prefs['recent'] = array_slice(array_values(array_unique(array_merge([$code], $prefs['recent']))), 0, self::RECENT_MAX);

		return $prefs;
	}

	/** Звёздочка: добавить в избранное или убрать. */
	public static function favorite(array $prefs, string $code, bool $on): array
	{
		$prefs = static::normalize($prefs);
		if(preg_match(self::CODE, $code) !== 1)
		{
			return $prefs;
		}
		$list = array_values(array_filter($prefs['favorite'], static fn(string $c): bool => $c !== $code));
		if($on)
		{
			$list[] = $code;
		}
		$prefs['favorite'] = array_slice($list, -self::FAVORITE_MAX);

		return $prefs;
	}

	/**
	 * Только открытые сотруднику роли (закрыли — из списков пропадает).
	 *
	 * @param list<string> $allowed
	 */
	public static function filter(array $prefs, array $allowed): array
	{
		$prefs = static::normalize($prefs);
		$keep = static fn(array $codes): array => array_values(array_filter($codes, static fn(string $c): bool => in_array($c, $allowed, true)));

		return ['recent' => $keep($prefs['recent']), 'favorite' => $keep($prefs['favorite'])];
	}

	/** @return list<string> */
	private static function codes(mixed $list): array
	{
		$codes = [];
		foreach(is_array($list) ? $list : [] as $code)
		{
			if(is_string($code) && preg_match(self::CODE, $code) === 1 && !in_array($code, $codes, true))
			{
				$codes[] = $code;
			}
		}

		return $codes;
	}
}
