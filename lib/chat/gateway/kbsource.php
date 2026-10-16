<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

use Bitrix\Main\Loader;
use Shef\ToolsAiAssistant\Chat\ContextBuilder;
use Shef\ToolsAiAssistant\Container;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Статьи базы знаний из shef.toolsaikb — мягкая зависимость.
 *
 * Контракт линейки: \Shef\ToolsAiKb\Public\Service\KbSearchService::find(string
 * $query, int $limit = 5): list<{id, title, url, snippet}>, с правами текущего
 * пользователя. Модуля нет, класса нет или поиск упал — пустой список: чат
 * отвечает без статей, сбой — в лог проблем.
 */
final class KbSource implements KbSourceInterface
{
	/** Установлен ли shef.toolsaikb и есть ли его сервис поиска. */
	public function isAvailable(): bool
	{
		return Loader::includeModule(Constants::KB_MODULE_ID) && class_exists(Constants::KB_SEARCH_CLASS);
	}

	/** Статьи под вопрос с правами текущего пользователя; нет модуля или сбой — пустой список. */
	public function find(string $query, int $limit): array
	{
		if($limit <= 0 || trim($query) === '' || !$this->isAvailable())
		{
			return [];
		}
		try
		{
			$class = Constants::KB_SEARCH_CLASS;

			return ContextBuilder::normalizeArticles($class::find(mb_substr($query, 0, 500), $limit), $limit);
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(self::class)?->warning('Поиск статей shef.toolsaikb не удался: '.$throwable->getMessage());

			return [];
		}
	}
}
