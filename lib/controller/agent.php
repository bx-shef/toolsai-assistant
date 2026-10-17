<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Controller;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Shef\ToolsAiAssistant\Access\UserAccess;
use Shef\ToolsAiAssistant\Agent\AgentStatus;
use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Chat\ChatRequest;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Container;
use Shef\ToolsAiAssistant\Main\Constants;
use Shef\ToolsAiAssistant\Mcp\McpException;

/**
 * Режим «Агент» (1.10.0): BX.ajax.runAction('shef:toolsaiassistant.agent.<действие>').
 *
 *   status     — включён ли агент сотруднику и подключён ли он к MCP;
 *   connect    — адрес входа в MCP-сервер (окно откроет его в новом окне);
 *   disconnect — забыть подключение сотрудника;
 *   run        — ответ агента: вопрос, шаги инструментов, ждущее действие;
 *                с confirm {id, decision: yes|no} — решение по нему.
 *
 * Все — POST, авторизация, sessid; run отпускает сессию до цикла модели.
 * Право agent.use, включённый агент и адрес сервера проверяет
 * Agent\AgentService (run) и AgentStatus (остальные).
 */
class Agent extends Controller
{
	public function configureActions(): array
	{
		$filters = [
			new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Csrf(),
		];

		return [
			'status' => ['prefilters' => $filters],
			'connect' => ['prefilters' => $filters],
			'disconnect' => ['prefilters' => $filters],
			'run' => ['prefilters' => [...$filters, new ActionFilter\CloseSession()]],
		];
	}

	public function statusAction(): ?array
	{
		return $this->guarded(fn(): array => AgentStatus::of(UserAccess::current(), Config::current(), $this->userId()));
	}

	/** Адрес входа; портал должен быть открыт по https (так требует MCP-сервер для адреса возврата). */
	public function connectAction(): ?array
	{
		return $this->guarded(function(): array
		{
			$config = Config::current();
			AgentStatus::assert(UserAccess::current(), $config);
			$request = Context::getCurrent()->getRequest();
			if(!$request->isHttps())
			{
				throw new ChatException('Подключение к MCP работает только на портале, открытом по https', ChatException::ACCESS);
			}
			$host = strtolower((string)$request->getHttpHost());

			return ['url' => Container::getMcpConnection($config)->start($this->userId(), 'https://'.$host.Constants::MCP_CALLBACK_URL, preg_replace('/:\d+$/', '', $host))];
		});
	}

	public function disconnectAction(): ?array
	{
		return $this->guarded(function(): array
		{
			Container::getMcpConnection()->disconnect($this->userId());

			return ['connected' => false];
		});
	}

	/**
	 * Ответ агента. Параметры — как у chat.answer, плюс $confirm — решение по
	 * ждущему действию {id, decision: yes|no}.
	 */
	public function runAction(array $messages = [], array $record = [], string $role = '', string $context = '', array $confirm = []): ?array
	{
		return $this->guarded(fn(): array => Container::getAgentService()->run(
			UserAccess::current(),
			$this->userId(),
			ChatRequest::parse($messages, $record, $role, Config::current()->history, $context),
			$confirm !== [] ? $confirm : null,
		));
	}

	private function guarded(callable $action): mixed
	{
		try
		{
			return $action();
		}
		catch(ChatException $exception)
		{
			$this->addError(new Error($exception->getMessage(), $exception->errorCode));
		}
		catch(McpException $exception)
		{
			$this->addError(new Error($exception->getMessage(), $exception->errorCode));
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(static::class)?->error($throwable);
			$this->addError(new Error('Агент временно недоступен — подробности в журнале модуля', 'internal'));
		}

		return null;
	}

	private function userId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}
}
