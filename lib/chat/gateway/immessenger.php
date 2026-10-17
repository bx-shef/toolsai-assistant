<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use Shef\ToolsAiAssistant\Chat\ChatException;

/**
 * Получатель — UserTable (имя в формате портала, отдел, тип внешнего
 * пользователя); отправка — \CIMMessage::Add() модуля im от имени сотрудника,
 * который нажал «Отправить», как если бы он написал сам (MESSAGE_TYPE ядро
 * ставит личным само, im/classes/general/im_message.php).
 */
final class ImMessenger implements MessengerInterface
{
	public function recipient(int $userId): ?array
	{
		$row = UserTable::getList([
			'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'WORK_POSITION', 'PERSONAL_BIRTHDAY', 'EXTERNAL_AUTH_ID', 'UF_DEPARTMENT'],
			'filter' => ['=ID' => $userId, '=ACTIVE' => 'Y'],
			'limit' => 1,
		])->fetch();
		if(!$row)
		{
			return null;
		}
		$external = (string)$row['EXTERNAL_AUTH_ID'] !== '' && in_array($row['EXTERNAL_AUTH_ID'], UserTable::getExternalUserTypes(), true);
		$departments = array_filter(array_map('intval', (array)($row['UF_DEPARTMENT'] ?? [])));
		$birthday = $row['PERSONAL_BIRTHDAY'] instanceof \Bitrix\Main\Type\Date ? $row['PERSONAL_BIRTHDAY']->format('Y-m-d') : null;

		return [
			'name' => (string)\CUser::FormatName(\CSite::GetNameFormat(false), $row, true, false),
			'position' => (string)$row['WORK_POSITION'],
			'birthday' => $birthday,
			'employee' => !$external && $departments !== [],
		];
	}

	public function send(int $fromUserId, int $toUserId, string $text): void
	{
		if(!Loader::includeModule('im'))
		{
			throw new ChatException('Мессенджер портала недоступен', ChatException::GREETING);
		}
		$id = \CIMMessage::Add(['FROM_USER_ID' => $fromUserId, 'TO_USER_ID' => $toUserId, 'MESSAGE' => $text]);
		if(!$id)
		{
			throw new ChatException('Сообщение не отправилось — попробуйте ещё раз', ChatException::GREETING);
		}
	}
}
