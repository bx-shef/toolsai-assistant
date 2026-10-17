<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use Shef\ToolsAiAssistant\Chat\ChatException;

/**
 * Личное сообщение модулем im: \CIMMessage::Add() — от имени сотрудника,
 * который нажал «Отправить», как если бы он написал сам. Получатель — только
 * активный сотрудник портала.
 */
final class ImMessenger implements MessengerInterface
{
	public function send(int $fromUserId, int $toUserId, string $text): void
	{
		if(!Loader::includeModule('im'))
		{
			throw new ChatException('Мессенджер портала недоступен', ChatException::GREETING);
		}
		$user = UserTable::getList(['select' => ['ID'], 'filter' => ['=ID' => $toUserId, '=ACTIVE' => 'Y'], 'limit' => 1])->fetch();
		if(!$user)
		{
			throw new ChatException('Сотрудник не найден или уволен', ChatException::GREETING);
		}
		$id = \CIMMessage::Add(['FROM_USER_ID' => $fromUserId, 'TO_USER_ID' => $toUserId, 'MESSAGE' => $text]);
		if(!$id)
		{
			throw new ChatException('Сообщение не отправилось — попробуйте ещё раз', ChatException::GREETING);
		}
	}
}
