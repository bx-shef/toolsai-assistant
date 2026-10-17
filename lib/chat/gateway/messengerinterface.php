<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

/** Мессенджер портала для поздравлений (1.11.0): кто получатель и личное сообщение ему. */
interface MessengerInterface
{
	/**
	 * Активный пользователь портала; нет — null. employee — сотрудник (есть
	 * отдел, не бот, не внешний), birthday — «Y-m-d» или null.
	 *
	 * @return array{name: string, position: string, birthday: ?string, employee: bool}|null
	 */
	public function recipient(int $userId): ?array;

	/** Отправить от $fromUserId; мессенджер недоступен или отказал — ChatException. */
	public function send(int $fromUserId, int $toUserId, string $text): void;
}
