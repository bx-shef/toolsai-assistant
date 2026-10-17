<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;
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

	/**
	 * Создать таблицу (установка и первая оценка после обновления модуля);
	 * таблица до 1.4.0 — добавить колонки разбора (до индексов: индекс по
	 * STATUS без колонки не построить).
	 */
	public static function install(): void
	{
		TableInstaller::addColumns(FeedbackTable::class, FeedbackTable::REVIEW_COLUMNS);
		TableInstaller::install(FeedbackTable::class, FeedbackTable::INDEXES);
	}

	/** Удалить таблицу (удаление без «сохранить данные»). */
	public static function uninstall(): void
	{
		TableInstaller::uninstall(FeedbackTable::class);
	}

	/**
	 * Таблица есть и с колонками разбора: модуль обновили файлами, а
	 * установщик не запускали (а Maintenance ещё не дошёл) — доводим при
	 * первой оценке или открытии списка.
	 */
	public static function ensure(): void
	{
		if(!self::$ensured)
		{
			$connection = Application::getConnection();
			$name = FeedbackTable::getTableName();
			if(!$connection->isTableExists($name)
				|| TableInstaller::missingColumns(array_keys($connection->getTableFields($name)), FeedbackTable::REVIEW_COLUMNS) !== [])
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
			'STATUS' => Review::NEW,
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

	/** Поля строки списка. */
	public const SELECT = ['ID', 'CREATED_AT', 'USER_ID', 'ROLE_CODE', 'CHAT_KEY', 'QUESTION', 'ANSWER', 'RATING', 'COMMENT', 'STATUS', 'EXPECTED', 'REVIEWED_BY', 'REVIEWED_AT'];

	/**
	 * Страница списка.
	 *
	 * @param array<string, mixed> $filter фильтр getList() (GridFilter::orm(); даты — строками Y-m-d H:i:s)
	 * @param array<string, string> $order GridFilter::sort()
	 * @return list<array<string, mixed>> поля SELECT
	 */
	public function list(int $limit, int $offset, array $filter = [], array $order = ['ID' => 'DESC']): array
	{
		static::ensure();

		return FeedbackTable::getList([
			'select' => self::SELECT,
			'filter' => static::filter($filter),
			'order' => $order,
			'limit' => $limit,
			'offset' => $offset,
		])->fetchAll();
	}

	/** Сколько оценок под фильтром. @param array<string, mixed> $filter */
	public function count(array $filter = []): int
	{
		static::ensure();

		return FeedbackTable::getCount(static::filter($filter));
	}

	/**
	 * Счёт оценок по роли и оценке — для сводки (RoleSummary).
	 *
	 * @param array<string, mixed> $filter
	 * @return list<array{ROLE_CODE: string, RATING: int, CNT: int}>
	 */
	public function countByRole(array $filter = []): array
	{
		static::ensure();

		return FeedbackTable::getList([
			'select' => ['ROLE_CODE', 'RATING', 'CNT'],
			'filter' => static::filter($filter),
			'group' => ['ROLE_CODE', 'RATING'],
			'runtime' => [new ExpressionField('CNT', 'COUNT(*)')],
		])->fetchAll();
	}

	/**
	 * Оценки по ID (выгрузка, окно оценки).
	 *
	 * @param list<int> $ids
	 * @return list<array<string, mixed>>
	 */
	public function byIds(array $ids): array
	{
		static::ensure();
		if($ids === [])
		{
			return [];
		}

		return FeedbackTable::getList(['select' => self::SELECT, 'filter' => ['@ID' => $ids], 'order' => ['ID' => 'ASC']])->fetchAll();
	}

	/**
	 * Поставить статус; отметка — кто и когда разобрал.
	 *
	 * @param list<int> $ids
	 * @return int сколько оценок изменено
	 */
	public function setStatus(array $ids, string $status, int $userId): int
	{
		static::ensure();
		$done = 0;
		foreach($ids as $id)
		{
			$done += FeedbackTable::update($id, ['STATUS' => $status, 'REVIEWED_BY' => $userId, 'REVIEWED_AT' => new DateTime()])->isSuccess() ? 1 : 0;
		}

		return $done;
	}

	/**
	 * «Что должно было быть»; новая оценка с ожидаемым становится разобранной.
	 *
	 * @return ?string статус после правки; оценки нет — null
	 */
	public function setExpected(int $id, string $expected, int $userId): ?string
	{
		static::ensure();
		$row = FeedbackTable::getList(['select' => ['ID', 'STATUS'], 'filter' => ['=ID' => $id]])->fetch();
		if(!is_array($row))
		{
			return null;
		}
		$status = (string)$row['STATUS'];
		if($expected !== '' && ($status === '' || $status === Review::NEW))
		{
			$status = Review::REVIEWED;
		}
		$result = FeedbackTable::update($id, ['EXPECTED' => $expected !== '' ? $expected : null, 'STATUS' => $status !== '' ? $status : Review::NEW, 'REVIEWED_BY' => $userId, 'REVIEWED_AT' => new DateTime()]);
		if(!$result->isSuccess())
		{
			throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
		}

		return $status;
	}

	/** Даты фильтра — строками Y-m-d H:i:s — в DateTime ядра. */
	private static function filter(array $filter): array
	{
		foreach(['>=CREATED_AT', '<=CREATED_AT'] as $key)
		{
			if(isset($filter[$key]) && is_string($filter[$key]))
			{
				$filter[$key] = DateTime::createFromPhp(new \DateTime($filter[$key]));
			}
		}

		return $filter;
	}
}
