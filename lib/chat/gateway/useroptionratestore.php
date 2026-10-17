<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Отметки вызовов — в пользовательских настройках ядра (\CUserOptions,
 * категория модуля, имя «rate»): не больше лимита чисел на сотрудника, без
 * своей таблицы. Гонка двух вкладок может пропустить лишний вызов —
 * ограничение защищает квоту от потока, а не считает до штуки.
 */
final class UserOptionRateStore implements RateStoreInterface
{
	/** @param string $name имя настройки: «rate» — ответы модели, «rate_feedback» — оценки (1.1.0) */
	public function __construct(private readonly string $name = 'rate')
	{
	}

	/** @return list<int> */
	public function get(int $userId): array
	{
		$value = \CUserOptions::GetOption(Constants::MODULE_ID, $this->name, [], $userId);

		return is_array($value) ? array_values(array_map('intval', $value)) : [];
	}

	/** @param list<int> $stamps */
	public function put(int $userId, array $stamps): void
	{
		\CUserOptions::SetOption(Constants::MODULE_ID, $this->name, array_values($stamps), false, $userId);
	}
}
