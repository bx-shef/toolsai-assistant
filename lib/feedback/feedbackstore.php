<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Shef\ToolsAiAssistant\Chat\Gateway\FeedbackStoreInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\UserOptionRateStore;
use Shef\ToolsAiAssistant\Feedback\Model\FeedbackTable;
use Shef\ToolsAiAssistant\Main\TableInstaller;

/**
 * Оценки ответов — таблица Model\FeedbackTable; отметки частоты — в
 * пользовательских настройках ядра (UserOptionRateStore, имя «rate_feedback»).
 */
final class FeedbackStore implements FeedbackStoreInterface
{
	private static bool $ensured = false;

	public function __construct(private readonly UserOptionRateStore $stamps = new UserOptionRateStore('rate_feedback'))
	{
	}

	/** Создать таблицу (установка и первая оценка после обновления модуля). */
	public static function install(): void
	{
		TableInstaller::install(FeedbackTable::class, FeedbackTable::INDEXES);
	}

	/** Удалить таблицу (удаление без «сохранить данные»). */
	public static function uninstall(): void
	{
		TableInstaller::uninstall(FeedbackTable::class);
	}

	/**
	 * Таблица есть: модуль обновили файлами поверх 1.0.0, а установщик не
	 * запускали — создаём при первой оценке или открытии списка.
	 */
	public static function ensure(): void
	{
		if(!self::$ensured)
		{
			if(!Application::getConnection()->isTableExists(FeedbackTable::getTableName()))
			{
				static::install();
			}
			self::$ensured = true;
		}
	}

	/** Имя агента очистки (CAgent). */
	public const AGENT = '\\Shef\\ToolsAiAssistant\\Feedback\\FeedbackStore::cleanAgent();';

	/** Поставить агент очистки раз в сутки, если его нет. */
	public static function installAgent(): void
	{
		$existing = \CAgent::GetList([], ['NAME' => self::AGENT, 'MODULE_ID' => \Shef\ToolsAiAssistant\Main\Constants::MODULE_ID])->Fetch();
		if(!is_array($existing))
		{
			\CAgent::AddAgent(self::AGENT, \Shef\ToolsAiAssistant\Main\Constants::MODULE_ID, 'N', 86400);
		}
	}

	/**
	 * Агент: удалить оценки старше срока хранения (настройка
	 * CHAT_feedbackdays, по умолчанию 90 дней) — в оценках фрагменты CRM.
	 */
	public static function cleanAgent(): string
	{
		try
		{
			static::clean(\Shef\ToolsAiAssistant\Config::current()->feedbackDays);
		}
		catch(\Throwable $throwable)
		{
			\Shef\ToolsAiAssistant\Container::getLogger(self::class)?->error($throwable);
		}

		return self::AGENT;
	}

	/** Удалить оценки старше $days дней. */
	public static function clean(int $days): void
	{
		$connection = Application::getConnection();
		if($days <= 0 || !$connection->isTableExists(FeedbackTable::getTableName()))
		{
			return;
		}
		$cutoff = (new DateTime())->add('-'.$days.' days');
		$connection->queryExecute('DELETE FROM '.FeedbackTable::getTableName().' WHERE CREATED_AT < '.$connection->getSqlHelper()->convertToDbDateTime($cutoff));
	}

	/** Сохранить оценку; вернуть ID. */
	public function add(int $userId, array $rating): int
	{
		static::ensure();
		$result = FeedbackTable::add([
			'CREATED_AT' => new DateTime(),
			'USER_ID' => $userId,
			'ROLE_CODE' => $rating['role'],
			'CHAT_KEY' => $rating['chatKey'],
			'QUESTION' => $rating['question'],
			'ANSWER' => $rating['answer'],
			'RATING' => $rating['rating'],
			'COMMENT' => $rating['comment'] !== '' ? $rating['comment'] : null,
		]);
		if(!$result->isSuccess())
		{
			throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
		}

		return (int)$result->getId();
	}

	/** @return list<int> */
	public function stamps(int $userId): array
	{
		return $this->stamps->get($userId);
	}

	/** @param list<int> $stamps */
	public function putStamps(int $userId, array $stamps): void
	{
		$this->stamps->put($userId, $stamps);
	}

	/**
	 * Страница списка: от свежих.
	 *
	 * @param array{rating?: int} $filter rating — только «полезно» (1) или «не помогло» (-1)
	 * @return list<array{ID: int, CREATED_AT: DateTime, USER_ID: int, ROLE_CODE: string, CHAT_KEY: string, QUESTION: string, ANSWER: string, RATING: int, COMMENT: ?string}>
	 */
	public function list(int $limit, int $offset, array $filter = []): array
	{
		static::ensure();

		return FeedbackTable::getList([
			'select' => ['ID', 'CREATED_AT', 'USER_ID', 'ROLE_CODE', 'CHAT_KEY', 'QUESTION', 'ANSWER', 'RATING', 'COMMENT'],
			'filter' => static::filter($filter),
			'order' => ['ID' => 'DESC'],
			'limit' => $limit,
			'offset' => $offset,
		])->fetchAll();
	}

	/** Сколько оценок под фильтром. @param array{rating?: int} $filter */
	public function count(array $filter = []): int
	{
		static::ensure();

		return FeedbackTable::getCount(static::filter($filter));
	}

	private static function filter(array $filter): array
	{
		return isset($filter['rating']) ? ['=RATING' => (int)$filter['rating']] : [];
	}
}
