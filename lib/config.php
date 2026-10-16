<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant;

use Bitrix\Main\Config\Option;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Настройки модуля (страница options_conf.php) — разобранные и с умолчаниями.
 *
 * Значение опции — всегда строка из формы. Разбор строгий: мусор даёт
 * умолчание, а не ноль. Разбор — fromValues(), чистый (config_test.php);
 * чтение из базы — current().
 */
final class Config
{
	public const DEFAULT_PATHS = ['/crm/', '/shef/toolsai/view/'];
	public const DEFAULT_HISTORY = 10;
	public const HISTORY_MAX = 30;
	public const DEFAULT_KB_LIMIT = 5;
	public const KB_LIMIT_MAX = 10;
	public const DEFAULT_RATE_LIMIT = 10;
	public const RATE_LIMIT_MAX = 120;

	/** Коды опций: <вкладка>_<опция> (shef.options). Менять нельзя — значения в базе. */
	public const OPTION_ENABLED = 'PANEL_enabled';
	public const OPTION_PATHS = 'PANEL_paths';
	public const OPTION_HISTORY = 'CHAT_history';
	public const OPTION_KB_LIMIT = 'CHAT_kblimit';
	public const OPTION_RATE_LIMIT = 'CHAT_ratelimit';

	/**
	 * @param list<string> $paths начала адресов страниц, где есть кнопка и панель
	 * @param int $rateLimit ответов модели на сотрудника в минуту; 0 — без ограничения
	 */
	public function __construct(
		public readonly bool $enabled = true,
		public readonly array $paths = self::DEFAULT_PATHS,
		public readonly int $history = self::DEFAULT_HISTORY,
		public readonly int $kbLimit = self::DEFAULT_KB_LIMIT,
		public readonly int $rateLimit = self::DEFAULT_RATE_LIMIT,
	)
	{
	}

	/** Настройки портала. */
	public static function current(): self
	{
		$get = static fn(string $code): string => (string)Option::get(Constants::MODULE_ID, $code, '');

		return static::fromValues([
			self::OPTION_ENABLED => $get(self::OPTION_ENABLED),
			self::OPTION_PATHS => $get(self::OPTION_PATHS),
			self::OPTION_HISTORY => $get(self::OPTION_HISTORY),
			self::OPTION_KB_LIMIT => $get(self::OPTION_KB_LIMIT),
			self::OPTION_RATE_LIMIT => $get(self::OPTION_RATE_LIMIT),
		]);
	}

	/**
	 * Разбор сохранённых значений. Пусто — умолчание.
	 *
	 * @param array<string, string> $values код опции => значение из формы
	 */
	public static function fromValues(array $values): self
	{
		$enabled = (string)($values[self::OPTION_ENABLED] ?? '') !== 'N';

		$paths = [];
		foreach(preg_split('/[\r\n,]+/', (string)($values[self::OPTION_PATHS] ?? '')) ?: [] as $path)
		{
			$path = trim($path);
			// Только путь своего сайта: «/crm/», не адрес и не мусор
			if($path !== '' && preg_match('~^/[\w\-./]*$~u', $path) && !in_array($path, $paths, true))
			{
				$paths[] = $path;
			}
		}

		return new self(
			enabled: $enabled,
			paths: $paths !== [] ? $paths : self::DEFAULT_PATHS,
			history: static::int($values[self::OPTION_HISTORY] ?? '', self::DEFAULT_HISTORY, 2, self::HISTORY_MAX),
			kbLimit: static::int($values[self::OPTION_KB_LIMIT] ?? '', self::DEFAULT_KB_LIMIT, 0, self::KB_LIMIT_MAX),
			rateLimit: static::int($values[self::OPTION_RATE_LIMIT] ?? '', self::DEFAULT_RATE_LIMIT, 0, self::RATE_LIMIT_MAX),
		);
	}

	/** Открыта ли панель на странице с этим путём. */
	public function matchesPath(string $path): bool
	{
		foreach($this->paths as $prefix)
		{
			if(str_starts_with($path, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	/** Целое из строки формы: только цифры, в пределах; иначе умолчание. */
	private static function int(mixed $value, int $default, int $min, int $max): int
	{
		$value = trim((string)$value);
		if(!preg_match('/^\d{1,4}$/', $value))
		{
			return $default;
		}

		return max($min, min($max, (int)$value));
	}
}
