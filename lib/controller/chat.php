<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Shef\ToolsAiAssistant\Access\UserAccess;
use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Chat\ChatRequest;
use Shef\ToolsAiAssistant\Chat\Record;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Container;

/**
 * Окно чата: BX.ajax.runAction('shef:toolsaiassistant.chat.<действие>').
 *
 *   info   — открытые роли, выбранная роль, готовые вопросы, модель;
 *   role   — сменить роль в чате (запоминается на сотрудника и чат);
 *   answer — ответ помощника.
 *
 * Все — только POST, авторизация и sessid (фильтры ядра). Права модуля и
 * право роли проверяет Chat\AnswerService на каждом вызове. Ответ — одним
 * JSON (не потоком): сессию отпускаем до вызова модели, чтобы другие вкладки
 * портала не ждали ответа (ActionFilter\CloseSession).
 */
class Chat extends Controller
{
	public function configureActions(): array
	{
		$filters = [
			new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Csrf(),
		];

		return [
			'info' => ['prefilters' => $filters],
			'role' => ['prefilters' => $filters],
			'answer' => ['prefilters' => [...$filters, new ActionFilter\CloseSession()]],
		];
	}

	/** Что показать в окне. $record — {entityTypeId, id} или пусто. */
	public function infoAction(array $record = []): ?array
	{
		return $this->run(fn(): array => Container::getAnswerService()->info(UserAccess::current(), $this->userId(), Record::fromArray($record)));
	}

	/** Сменить роль в этом чате. */
	public function roleAction(string $role = '', array $record = []): ?array
	{
		return $this->run(fn(): array => Container::getAnswerService()->chooseRole(UserAccess::current(), $this->userId(), Record::fromArray($record), $role));
	}

	/**
	 * Ответ помощника. $messages — [{role: user|assistant, text}], последний —
	 * вопрос; $role — код роли (пусто — запомненная или по умолчанию).
	 */
	public function answerAction(array $messages = [], array $record = [], string $role = ''): ?array
	{
		return $this->run(fn(): array => Container::getAnswerService()->answer(
			UserAccess::current(),
			$this->userId(),
			ChatRequest::parse($messages, $record, $role, Config::current()->history)
		));
	}

	/** Отказ сценария — ошибкой ответа с кодом; прочий сбой — в лог, наружу общий текст. */
	private function run(callable $action): ?array
	{
		try
		{
			return $action();
		}
		catch(ChatException $exception)
		{
			$this->addError(new Error($exception->getMessage(), $exception->errorCode));
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(static::class)?->error($throwable);
			$this->addError(new Error('Чат временно недоступен — подробности в журнале модуля', 'internal'));
		}

		return null;
	}

	private function userId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}
}
