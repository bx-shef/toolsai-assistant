<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Panel;

use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Container;
use Shef\ToolsAiAssistant\Public\Event\OnChatPlacesCollectEvent;

/**
 * Готовые места для настройки «Где показывать чат» (1.3.0): свои (CRM) и
 * присланные другими модулями через событие
 * shef.toolsaiassistant:OnChatPlacesCollect (docs/guide/04-events.md).
 *
 * Место — массив {id, title, url, group}, тот же формат, что у
 * shef.toolsaiannounce (OnAnnouncementPlacesCollect). В настройке хранится
 * адрес места (url), поэтому сохранённые до 1.3.0 адреса читаются как есть:
 * совпал с адресом готового места — показывается его названием, нет — как
 * свой адрес.
 *
 * Разбор ответов и слияние — чистые (normalize(), merge(); tests/places_test.php),
 * отправка события — collect().
 *
 * @phpstan-type Place array{id: string, title: string, url: string, group: string}
 */
final class PlaceCatalog
{
	/** Группа своих мест модуля. */
	public const GROUP_CRM = 'CRM';

	/** @var list<Place>|null места за хит */
	private static ?array $cache = null;

	/**
	 * Свои готовые места модуля.
	 *
	 * @return list<Place>
	 */
	public static function builtin(): array
	{
		$crm = static fn(string $id, string $title, string $url): array => ['id' => $id, 'title' => $title, 'url' => $url, 'group' => self::GROUP_CRM];

		return [
			$crm('crm', 'Вся CRM', '/crm/'),
			$crm('crm.deal', 'Сделки', '/crm/deal/'),
			$crm('crm.lead', 'Лиды', '/crm/lead/'),
			$crm('crm.contact', 'Контакты', '/crm/contact/'),
			$crm('crm.company', 'Компании', '/crm/company/'),
			$crm('crm.quote', 'Предложения', '/crm/quote/'),
			$crm('crm.activity', 'Дела', '/crm/activity/'),
			$crm('crm.type', 'Смарт-процессы', '/crm/type/'),
		];
	}

	/**
	 * Все готовые места: свои и от других модулей. Раз за хит; сбой события —
	 * в лог, остаются свои места.
	 *
	 * @return list<Place>
	 */
	public static function collect(): array
	{
		if(self::$cache !== null)
		{
			return self::$cache;
		}

		$lists = [static::builtin()];
		try
		{
			$event = new OnChatPlacesCollectEvent();
			$event->send();
			foreach($event->getResults() as $result)
			{
				if($result instanceof EventResult && $result->getType() === EventResult::SUCCESS)
				{
					$parameters = $result->getParameters();
					$lists[] = static::normalize(is_array($parameters) ? ($parameters['places'] ?? null) : null, (string)$result->getModuleId());
				}
			}
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(self::class)?->error($throwable);
		}

		return self::$cache = static::merge(...$lists);
	}

	/** Забыть места хита (тесты, после установки модуля). */
	public static function forget(): void
	{
		self::$cache = null;
	}

	/**
	 * Разобрать места из ответа обработчика. Негодное место пропускается
	 * молча: чужая ошибка не должна ломать настройку.
	 *
	 * - id — непустая строка до 100 знаков (как прислали);
	 * - title — непустая строка (обрезается до 100 знаков);
	 * - url — путь своего сайта (PlaceUrl::normalize());
	 * - group — строка (обрезается до 100 знаков); пусто — ID модуля ответа,
	 *   нет и его — «CRM» (GROUP_CRM).
	 *
	 * @return list<Place>
	 */
	public static function normalize(mixed $places, string $moduleId): array
	{
		if(!is_array($places))
		{
			return [];
		}

		$result = [];
		foreach($places as $place)
		{
			if(!is_array($place))
			{
				continue;
			}
			$id = trim(is_string($place['id'] ?? null) ? $place['id'] : '');
			$title = trim(is_string($place['title'] ?? null) ? $place['title'] : '');
			$url = PlaceUrl::normalize(is_string($place['url'] ?? null) ? $place['url'] : '');
			$group = trim(is_string($place['group'] ?? null) ? $place['group'] : '');
			if($id === '' || mb_strlen($id) > 100 || $title === '' || $url === null)
			{
				continue;
			}
			$result[] = [
				'id' => $id,
				'title' => mb_substr($title, 0, 100),
				'url' => $url,
				'group' => $group !== '' ? mb_substr($group, 0, 100) : ($moduleId !== '' ? $moduleId : self::GROUP_CRM),
			];
		}

		return $result;
	}

	/**
	 * Слить списки мест: повтор адреса — берётся первое (свои места модуля
	 * идут первыми). Ключ места в настройке — адрес, поэтому одинаковые id у
	 * разных модулей не мешают.
	 *
	 * @param list<Place> ...$lists
	 * @return list<Place>
	 */
	public static function merge(array ...$lists): array
	{
		$result = [];
		$urls = [];
		foreach($lists as $list)
		{
			foreach($list as $place)
			{
				if(isset($urls[$place['url']]))
				{
					continue;
				}
				$urls[$place['url']] = true;
				$result[] = $place;
			}
		}

		return $result;
	}

	/**
	 * Готовое место по адресу; нет — null (это свой адрес).
	 *
	 * @param list<Place> $places
	 * @return Place|null
	 */
	public static function findByUrl(array $places, string $url): ?array
	{
		foreach($places as $place)
		{
			if($place['url'] === $url)
			{
				return $place;
			}
		}

		return null;
	}
}
