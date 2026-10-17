<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

/**
 * Имена из записи CRM чата — их Anonymizer заменит в кейсе проверки (1.5.0):
 * заголовок записи, ФИО и компания лида или контакта, компания и контакты
 * сделки (смарт-процесса), ответственный.
 *
 * Чтение — CRM ядра (Crm\Service\Container: getUserPermissions()->
 * checkReadPermissions(), getFactory()->getItem(); crm/lib/Service/) с
 * правами того, кто выгружает: не видит запись — имён из неё нет (и истории
 * в кейсе нет). Сбой — пусто, выгрузку не роняет.
 *
 * fromFields() — чистая часть (feedback_test.php).
 */
final class RecordNames
{
	/** Поля записи с именами (Crm\Item). */
	public const FIELDS = ['TITLE', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'COMPANY_TITLE'];

	/**
	 * Имена из полей записи и связанных: без пустых и повторов, «Иван Петров» — ещё и целиком.
	 *
	 * @param array<string, mixed> $fields поля записи (FIELDS)
	 * @param list<string> $related заголовки компании и контактов, имя ответственного
	 * @return list<string>
	 */
	public static function fromFields(array $fields, array $related = []): array
	{
		$names = [];
		foreach(self::FIELDS as $field)
		{
			$names[] = is_scalar($fields[$field] ?? null) ? (string)$fields[$field] : '';
		}
		$names[] = trim(implode(' ', array_filter([$fields['NAME'] ?? '', $fields['SECOND_NAME'] ?? '', $fields['LAST_NAME'] ?? ''], 'is_string')));
		array_push($names, ...array_map('strval', $related));
		$out = [];
		foreach($names as $name)
		{
			$name = trim((string)preg_replace('/\s+/u', ' ', $name));
			if(mb_strlen($name) >= 2)
			{
				$out[$name] = true;
			}
		}

		return array_keys($out);
	}

	/**
	 * Имена записи, видимой сотруднику $userId; нет CRM, нет права, сбой — [].
	 *
	 * @return list<string>
	 */
	public static function load(int $entityTypeId, int $id, int $userId): array
	{
		try
		{
			if($userId <= 0 || !\Bitrix\Main\Loader::includeModule('crm'))
			{
				return [];
			}
			$container = \Bitrix\Crm\Service\Container::getInstance();
			$permissions = $container->getUserPermissions($userId);
			$item = $permissions->checkReadPermissions($entityTypeId, $id) ? $container->getFactory($entityTypeId)?->getItem($id) : null;
			if($item === null)
			{
				return [];
			}
			$fields = [];
			foreach(self::FIELDS as $field)
			{
				$fields[$field] = $item->hasField($field) ? $item->get($field) : null;
			}
			$related = [];
			$heading = static function(int $typeId, int $relatedId) use ($container, $permissions): ?string
			{
				if($relatedId <= 0 || !$permissions->checkReadPermissions($typeId, $relatedId))
				{
					return null;
				}

				return $container->getFactory($typeId)?->getItem($relatedId)?->getHeading();
			};
			if($item->hasField('COMPANY_ID'))
			{
				$related[] = $heading(\CCrmOwnerType::Company, (int)$item->get('COMPANY_ID'));
			}
			if($item->hasField('CONTACT_IDS'))
			{
				foreach(array_slice((array)$item->get('CONTACT_IDS'), 0, 10) as $contactId)
				{
					$related[] = $heading(\CCrmOwnerType::Contact, (int)$contactId);
				}
			}
			if($item->hasField('ASSIGNED_BY_ID'))
			{
				$related[] = FeedbackPage::userNames([(int)$item->get('ASSIGNED_BY_ID')])[(int)$item->get('ASSIGNED_BY_ID')] ?? null;
			}

			return static::fromFields($fields, array_values(array_filter($related, 'is_string')));
		}
		catch(\Throwable)
		{
			return [];
		}
	}
}
