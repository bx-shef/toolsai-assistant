<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Prompt;

use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Типовые запросы меню промптов (1.7.0) — как «Улучши описание задачи» у задач
 * Битрикс24: стандартные навыки модуля в реестре shef.toolsai, общие для всех
 * ролей (parent пуст), в местах чата (ChatPlaces). Администратор правит,
 * выключает и добавляет свои в «Библиотеке промптов» shef.toolsai.
 *
 * Текст навыка — это сам запрос сотрудника (type = plain — отправить сразу,
 * template — вставить в поле, уточнения «[ … ]» дописать). Подсказку о
 * виджете оставляем в запросе: модель знает о них из системного текста.
 *
 * Чистый класс — без ядра (prompts_test.php).
 */
final class TypicalPrompts
{
	/** @return list<array<string, mixed>> описания для Prompt\Definition::fromArray() */
	public static function raw(): array
	{
		$card = [ChatPlaces::full(ChatPlaces::CARD)];
		$list = [ChatPlaces::full(ChatPlaces::LIST)];
		$chat = [ChatPlaces::full(ChatPlaces::CHAT)];

		return [
			static::skill('card.summary', 'Кратко о записи и следующий шаг', $card, 'any', 10, 'plain', 'set:shining',
				'Кратко: что происходит с этой записью, что мешает и какой следующий шаг со сроком. Ключевые цифры покажи виджетом kpi, следующий шаг — кнопками actions.'),
			static::skill('card.risks', 'Риски сделки', $card, 'deal', 20, 'plain', 'set:warning',
				'Оцени риски потерять эту сделку: что тревожит в истории, насколько это серьёзно и что сделать сегодня.'),
			static::skill('card.promised', 'Что обещали клиенту', $card, 'any', 30, 'plain', 'set:circle-check',
				'Что мы обещали клиенту по этой записи и что из этого ещё не сделано? Таблицей: обещание, кто, когда, выполнено ли.'),
			static::skill('card.letter', 'Письмо клиенту', $card, 'any', 40, 'template', 'set:chat-message',
				'Напиши письмо клиенту: [о чём письмо]. Тон — [деловой / дружелюбный]. Только факты из истории.'),
			static::skill('card.call', 'Подготовка к звонку', $card, 'any', 50, 'plain', 'set:person',
				'Подготовь меня к звонку клиенту: что он уже знает, что спросить, какие возражения возможны и что предложить.'),
			static::skill('list.stuck', 'Что застряло', $list, 'any', 10, 'plain', 'set:black-clock',
				'Какие записи в этом списке застряли и почему? Покажи таблицей: запись, сколько без движения, что сделать.'),
			static::skill('list.today', 'Кому позвонить сегодня', $list, 'any', 20, 'plain', 'set:bell',
				'Кому из этого списка стоит позвонить сегодня и с чем? Коротко, по приоритету.'),
			static::skill('list.funnel', 'Сводка по воронке', $list, 'any', 30, 'plain', 'set:crm',
				'Сводка по этому списку: сколько записей на каких стадиях, где узкое место. Покажи виджетом bars.'),
			static::skill('chat.explain', 'Объясни это', $chat, 'any', 90, 'template', 'set:info-circle',
				'Объясни простыми словами: [что объяснить]'),
			static::skill('chat.rewrite', 'Перепиши понятнее', $chat, 'any', 95, 'template', 'set:rocket',
				'Перепиши понятнее и короче: [текст]'),
		];
	}

	/** @return list<string> коды */
	public static function codes(): array
	{
		return array_column(static::raw(), 'code');
	}

	private static function skill(string $name, string $title, array $places, string $entity, int $sort, string $type, string $icon, string $text): array
	{
		return [
			'code' => Constants::MODULE_ID.':prompt.'.$name,
			'kind' => 'skill',
			'title' => $title,
			'parent' => '',
			'places' => $places,
			'entity' => $entity,
			'sort' => $sort,
			'type' => $type,
			'icon' => $icon,
			'description' => 'Типовой запрос меню промптов чата помощника.',
			'text' => $text,
		];
	}
}
