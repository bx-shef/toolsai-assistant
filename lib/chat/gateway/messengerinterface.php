<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

/** Личное сообщение в мессенджере портала от сотрудника сотруднику (1.11.0, поздравление). */
interface MessengerInterface
{
	/** Отправить; получателя нет, он уволен или мессенджер недоступен — ChatException. */
	public function send(int $fromUserId, int $toUserId, string $text): void;
}
