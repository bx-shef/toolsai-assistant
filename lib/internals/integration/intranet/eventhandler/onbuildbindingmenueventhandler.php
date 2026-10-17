<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Internals\Integration\Intranet\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Shef\ToolsAiAssistant\Panel\SidePanel;

/**
 * intranet:onBuildBindingMenu — пункт «✦ Спросить ИИ» в меню-шестерёнке
 * карточки CRM (1.3.2): сделка, лид, контакт, компания, предложение, счёт
 * (новый) и смарт-процессы.
 *
 * Это штатный путь расширить то меню из модуля коробки, без правки ядра:
 * - меню — компонент intranet.binding.menu, раздел crm_detail (карточки:
 *   crm/lib/component/entitydetails/factorybased.php,
 *   getIntranetBindingMenuParameters(); crm.interface.toolbar/templates/slider);
 * - пункты других модулей собирает intranet/lib/binding/menu.php (getBindings(),
 *   событие onBuildBindingMenu) — так встают «Умные сценарии» CRM
 *   (crm/lib/integration/intranet/bindingmenu.php).
 *
 * Меню кешируется общим для всех сотрудников (тег intranet_menu_binding,
 * getMenuItems()), поэтому право здесь не проверяем: пункт видят все, а
 * клик открывает чат, только если панель подключена на странице (место из
 * «Где показывать чат» и право «Пользоваться чатом» — OnProlog), иначе —
 * уведомление. Запись берётся из адреса карточки (chat.js, openCurrent()),
 * сервер всё равно проверяет право на запись при каждом ответе.
 */
final class OnBuildBindingMenuEventHandler
{
	/** Раздел меню карточки CRM (Crm\Integration\Intranet\BindingMenu\SectionCode::DETAIL). */
	public const SECTION = 'crm_detail';
	/** Карточки с меню всегда (коды MapItem раздела crm_detail в intranet/lib/binding/menu.php). */
	public const ENTITIES = ['deal', 'lead', 'contact', 'company', 'quote', 'smart_invoice'];
	public const ITEM_ID = 'shef_toolsaiassistant_ask';
	/** Тег кеша меню — сбросить после подписки, чтобы пункт появился сразу. */
	public const CACHE_TAG = 'intranet_menu_binding';

	/** @return list<array{bindings: array, items: list<array>}> */
	public static function handle(Event $event): array
	{
		try
		{
			return static::places(array_merge(self::ENTITIES, static::dynamicEntities()));
		}
		catch(\Throwable)
		{
			// Меню карточки не ломаем: просто без пункта
			return [];
		}
	}

	/**
	 * Описание пункта для intranet по списку кодов карточек. Чистая часть.
	 *
	 * @param list<string> $entities коды меню (deal, dynamic_128…)
	 * @return list<array{bindings: array, items: list<array>}>
	 */
	public static function places(array $entities): array
	{
		$entities = array_values(array_unique(array_filter($entities, static fn($code): bool => is_string($code) && preg_match('/^[a-z][a-z0-9_]*$/', $code) === 1)));
		if(!$entities)
		{
			return [];
		}

		return [[
			'bindings' => [self::SECTION => ['include' => $entities]],
			'items' => [[
				'id' => self::ITEM_ID,
				'text' => SidePanel::DETAIL_MENU_TEXT,
				'onclick' => SidePanel::openCurrentJs(),
				'sort' => 10,
				// intranet подключит расширение при выводе меню (getMenuItems()): уведомление «недоступен»
				'extension' => 'ui.notification',
			]],
		]];
	}

	/** Коды смарт-процессов: dynamic_<entityTypeId> (Crm\…\BindingMenu\CodeBuilder::getMenuCode). */
	private static function dynamicEntities(): array
	{
		if(!Loader::includeModule('crm'))
		{
			return [];
		}
		$map = \Bitrix\Crm\Service\Container::getInstance()->getDynamicTypesMap();
		$map->load(['isLoadStages' => false, 'isLoadCategories' => false]);
		$codes = [];
		foreach($map->getTypes() as $type)
		{
			$codes[] = mb_strtolower(\CCrmOwnerType::ResolveName($type->getEntityTypeId()));
		}

		return $codes;
	}

	/** Сбросить кеш меню (после подписки и при удалении). */
	public static function clearCache(): void
	{
		if(defined('BX_COMP_MANAGED_CACHE') && class_exists(\Bitrix\Main\Application::class))
		{
			\Bitrix\Main\Application::getInstance()->getTaggedCache()->clearByTag(self::CACHE_TAG);
		}
	}
}
