<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Agent;

use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Container;
use Shef\ToolsAiAssistant\Public\Event\OnAfterAgentToolCallEvent;
use Shef\ToolsAiAssistant\Public\Event\OnAgentToolsCollectEvent;
use Shef\ToolsAiAssistant\Public\Event\OnBeforeAgentToolCallEvent;

/**
 * События агента на портале (AgentHooksInterface). Сбой обработчика модуля
 * агента не ломает: он пишется в журнал и пропускается. Запрет — только
 * явный ответ EventResult::ERROR.
 */
final class AgentEvents implements AgentHooksInterface
{
	public function toolRules(int $userId, array $names): array
	{
		$rules = [];
		try
		{
			$event = new OnAgentToolsCollectEvent($userId, $names);
			$event->send();
			foreach($event->getResults() as $result)
			{
				if($result->getType() === EventResult::SUCCESS && is_array($result->getParameters()))
				{
					$rules[] = $result->getParameters();
				}
			}
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(static::class)?->error($throwable);
		}

		return $rules;
	}

	public function beforeCall(int $userId, array $tool, array $arguments): ?string
	{
		try
		{
			$event = new OnBeforeAgentToolCallEvent($userId, $tool, $arguments);
			$event->send();
			foreach($event->getResults() as $result)
			{
				if($result->getType() === EventResult::ERROR)
				{
					$message = $result->getParameters()['message'] ?? '';

					return is_string($message) && trim($message) !== '' ? mb_substr(trim($message), 0, 300) : 'Вызов запрещён настройками портала';
				}
			}
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(static::class)?->error($throwable);
		}

		return null;
	}

	public function afterCall(int $userId, array $tool, array $arguments, bool $ok): void
	{
		try
		{
			(new OnAfterAgentToolCallEvent($userId, $tool, $arguments, $ok))->send();
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(static::class)?->error($throwable);
		}
	}
}
