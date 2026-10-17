<?php

/**
 * Подмена PromptService shef.toolsai 1.54.0 (lib/public/service/promptservice.php):
 * сигнатуры — как там, что они есть на самом деле — сверяет
 * tests/toolsaiapi_test.php. Логики нет: ответы задаёт тест, вызовы
 * запоминаются.
 */

namespace Shef\ToolsAi\Public\Service
{
	final class PromptService
	{
		public const PLACE_ASSISTANT = 'assistant';
		public const USER_CURRENT = -1;
		public const USER_ANY = 0;

		public static array $roles = [];
		public static array $open = [];
		public static array $composed = ['role' => null, 'skill' => null, 'system' => '', 'examples' => [], 'type' => 'plain'];
		public static array $prompts = [];
		public static array $calls = [];

		public static function roles(?array $allowed = null, int $userId = self::USER_CURRENT): array
		{
			static::$calls[] = ['roles', $allowed, $userId];

			return static::$roles;
		}

		public static function isAvailable(string $code, int $userId = self::USER_CURRENT): bool
		{
			static::$calls[] = ['isAvailable', $code, $userId];

			return in_array($code, static::$open, true);
		}

		public static function compose(string $roleCode, string $place, int $entityTypeId = 0, ?array $allowed = null, int $userId = self::USER_CURRENT): array
		{
			static::$calls[] = ['compose', $roleCode, $place, $entityTypeId, $allowed, $userId];

			return static::$composed;
		}

		public static function get(string $code): ?array
		{
			static::$calls[] = ['get', $code];

			return static::$prompts[$code] ?? null;
		}
	}
}
