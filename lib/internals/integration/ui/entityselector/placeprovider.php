<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Internals\Integration\Ui\EntitySelector;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\Tab;
use Shef\ToolsAiAssistant\Panel\PlaceCatalog;
use Shef\ToolsAiAssistant\Panel\PlaceUrl;

/**
 * Провайдер ui.entity-selector «Места чата» (1.3.0): сущность
 * shef-assistant-place, регистрация — 'ui.entity-selector' в .settings.php.
 *
 * Элемент — место из Panel\PlaceCatalog::collect(); id элемента — адрес места
 * (так он и хранится в настройке PANEL_paths). Свой адрес — элемент с id,
 * равным адресу, и подписью «Свой адрес»: его создаёт окно настройки
 * (Panel\PlacesOption), а при открытии формы провайдер узнаёт его по id.
 *
 * Сами места — не тайна, но список отдаём только администратору: выбирать их
 * может только он (страница настроек модуля).
 */
final class PlaceProvider extends BaseProvider
{
	public const ENTITY_ID = 'shef-assistant-place';
	public const TAB_ID = 'shef-assistant-places';

	public function __construct(array $options = [])
	{
		parent::__construct();
		$this->options = [];
	}

	public function isAvailable(): bool
	{
		return CurrentUser::get()->isAdmin();
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addTab(new Tab([
			'id' => self::TAB_ID,
			'title' => 'Разделы',
			'stub' => true,
		]));
		foreach(PlaceCatalog::collect() as $place)
		{
			$dialog->addItem(static::placeItem($place));
		}
	}

	/** @return Item[] */
	public function getItems(array $ids): array
	{
		$places = PlaceCatalog::collect();
		$items = [];
		foreach($ids as $id)
		{
			$url = PlaceUrl::normalize((string)$id);
			if($url === null)
			{
				continue;
			}
			$place = PlaceCatalog::findByUrl($places, $url);
			$items[] = $place !== null ? static::placeItem($place) : static::customItem($url);
		}

		return $items;
	}

	/** @param array{id: string, title: string, url: string, group: string} $place */
	private static function placeItem(array $place): Item
	{
		return new Item([
			'id' => $place['url'],
			'entityId' => self::ENTITY_ID,
			'title' => $place['title'],
			'subtitle' => $place['url'],
			'supertitle' => $place['group'],
			'tabs' => self::TAB_ID,
			'customData' => ['placeId' => $place['id'], 'url' => $place['url'], 'group' => $place['group']],
		]);
	}

	private static function customItem(string $url): Item
	{
		return new Item([
			'id' => $url,
			'entityId' => self::ENTITY_ID,
			'entityType' => 'custom',
			'title' => $url,
			'subtitle' => 'Свой адрес',
			'customData' => ['url' => $url],
		]);
	}
}
