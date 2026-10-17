<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant;

use Bitrix\Main\Config\Option;
use Shef\ToolsAiAssistant\Main\Constants;
use Shef\ToolsAiAssistant\Panel\PlaceUrl;

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
	public const DEFAULT_FEEDBACK_DAYS = 90;
	public const FEEDBACK_DAYS_MAX = 3650;

	/** Коды опций: <вкладка>_<опция> (shef.options). Менять нельзя — значения в базе. */
	public const OPTION_ENABLED = 'PANEL_enabled';
	public const OPTION_PATHS = 'PANEL_paths';
	public const OPTION_HISTORY = 'CHAT_history';
	public const OPTION_KB_LIMIT = 'CHAT_kblimit';
	public const OPTION_RATE_LIMIT = 'CHAT_ratelimit';
	public const OPTION_FEEDBACK_DAYS = 'CHAT_feedbackdays';

	/**
	 * @param list<string> $paths адреса мест, где есть кнопка и панель: начало
	 *   пути и, при желании, параметры запроса (Panel\PlaceUrl, 1.3.0)
	 * @param int $rateLimit ответов модели на сотрудника в минуту; 0 — без ограничения
	 */
	public function __construct(
		public readonly bool $enabled = true,
		public readonly array $paths = self::DEFAULT_PATHS,
		public readonly int $history = self::DEFAULT_HISTORY,
		public readonly int $kbLimit = self::DEFAULT_KB_LIMIT,
		public readonly int $rateLimit = self::DEFAULT_RATE_LIMIT,
		public readonly int $feedbackDays = self::DEFAULT_FEEDBACK_DAYS,
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
			self::OPTION_FEEDBACK_DAYS => $get(self::OPTION_FEEDBACK_DAYS),
		]);
	}

	/**
	 * Разбор сохранённых значений. Пусто — умолчание; кроме адресов панели:
	 * сохранённое пустое «Где показывать чат» — чат нигде (1.1.0), умолчание —
	 * только когда настройки нет вовсе (ключ не передан).
	 *
	 * @param array<string, string> $values код опции => значение из формы
	 */
	public static function fromValues(array $values): self
	{
		$enabled = (string)($values[self::OPTION_ENABLED] ?? '') !== 'N';

		$paths = [];
		foreach(preg_split('/[\r\n,]+/', (string)($values[self::OPTION_PATHS] ?? '')) ?: [] as $path)
		{
			// Только путь своего сайта: «/crm/», «/shef/toolsai/view/?type=2», не адрес и не мусор
			$path = PlaceUrl::normalize($path);
			if($path !== null && !in_array($path, $paths, true))
			{
				$paths[] = $path;
			}
		}

		return new self(
			enabled: $enabled,
			paths: array_key_exists(self::OPTION_PATHS, $values) ? $paths : self::DEFAULT_PATHS,
			history: static::int($values[self::OPTION_HISTORY] ?? '', self::DEFAULT_HISTORY, 2, self::HISTORY_MAX),
			kbLimit: static::int($values[self::OPTION_KB_LIMIT] ?? '', self::DEFAULT_KB_LIMIT, 0, self::KB_LIMIT_MAX),
			rateLimit: static::int($values[self::OPTION_RATE_LIMIT] ?? '', self::DEFAULT_RATE_LIMIT, 0, self::RATE_LIMIT_MAX),
			feedbackDays: static::int($values[self::OPTION_FEEDBACK_DAYS] ?? '', self::DEFAULT_FEEDBACK_DAYS, 1, self::FEEDBACK_DAYS_MAX),
		);
	}

	/**
	 * Открыта ли панель на этой странице; список адресов пуст — нигде.
	 *
	 * @param string $requestUri путь страницы с запросом (1.3.0: учитываются
	 *   параметры места, PlaceUrl::matches())
	 */
	public function matchesPath(string $requestUri): bool
	{
		foreach($this->paths as $placeUrl)
		{
			if(PlaceUrl::matches($placeUrl, $requestUri))
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
