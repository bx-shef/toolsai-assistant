<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Prompt;

use Shef\ToolsAiAssistant\Chat\Record;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Места промптов чата (1.7.0) в «Где доступен» библиотеки промптов shef.toolsai:
 * чат в целом, чат у списка CRM и чат в карточке записи. В навыке место хранится
 * как «shef.toolsaiassistant:<место>» (shef.toolsai, Prompt\Places).
 *
 * Меню промптов в поле ввода показывает навыки мест, подходящих окну: карточка —
 * общие + карточки, список — общие + списка, справка — ничего (там свои вопросы).
 *
 * Чистый класс — без ядра (prompts_test.php).
 */
final class ChatPlaces
{
	public const CHAT = 'chat';
	public const LIST = 'chat.list';
	public const CARD = 'chat.card';

	/** Подписи для мастера библиотеки (событие OnPromptPlacesCollect). */
	public const TITLES = [
		self::CHAT => 'Чат помощника: везде',
		self::LIST => 'Чат помощника: список CRM',
		self::CARD => 'Чат помощника: карточка записи',
	];

	public const GROUP = 'Чат ИИ-помощника';

	/** «Библиотека промптов» shef.toolsai — ссылка внизу меню (публичная страница его настроек). */
	public const LIBRARY_URL = '/shef/toolsai/?section=promptlib';

	/** Полный код места в навыке. */
	public static function full(string $place): string
	{
		return Constants::MODULE_ID.':'.$place;
	}

	/**
	 * Места для окна: запись — карточка, view = list — список, справка — пусто.
	 *
	 * @return list<string> полные коды
	 */
	public static function forWindow(?Record $record, string $view, string $context): array
	{
		if($context === 'docs')
		{
			return [];
		}
		$places = [self::CHAT];
		if($record !== null)
		{
			$places[] = self::CARD;
		}
		elseif($view === 'list')
		{
			$places[] = self::LIST;
		}

		return array_map(static fn(string $p): string => static::full($p), $places);
	}

	/** Вид окна из запроса: только «list»; остальное — пусто. */
	public static function view(mixed $raw): string
	{
		return $raw === 'list' ? 'list' : '';
	}

	/** Группа промпта в меню по его местам: карточка, список или общий. */
	public static function group(array $places): string
	{
		if(in_array(static::full(self::CARD), $places, true))
		{
			return 'card';
		}

		return in_array(static::full(self::LIST), $places, true) ? 'list' : 'chat';
	}

	/** Ответ на shef.toolsai:OnPromptPlacesCollect. @return list<array{id: string, title: string, group: string}> */
	public static function forLibrary(): array
	{
		$out = [];
		foreach(self::TITLES as $id => $title)
		{
			$out[] = ['id' => $id, 'title' => $title, 'group' => self::GROUP];
		}

		return $out;
	}
}
