<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Agent;

use Shef\ToolsAiAssistant\Access\Rights;
use Shef\ToolsAiAssistant\Chat\AnswerService;
use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Chat\ChatRequest;
use Shef\ToolsAiAssistant\Chat\Gateway\ModelGatewayInterface;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Mcp\McpException;

/**
 * Режим «Агент» (1.9.0): помощник действует в портале инструментами
 * MCP-сервера toolsai-mcp от имени сотрудника.
 *
 * Один вопрос — цикл: модель → блок ```tool → вызов → результат модели →
 * … → ответ сотруднику. Чтение агент вызывает сам (настройка «Читать без
 * вопроса»), запись и всё, что модули пометили confirm, — останавливает цикл:
 * вызов сохраняется на сервере (PendingStoreInterface), окно показывает
 * карточку «Выполнить / Отмена» и присылает только id и решение. Шагов на
 * вопрос — не больше Config::$agentSteps; кончились — модель отвечает по
 * собранному.
 *
 * Всё до цикла — как у обычного ответа (AnswerService::prepare(): право,
 * роль, частота, запись CRM, статьи); плюс право agent.use и включённый агент.
 * Переписка шагов (trace) живёт в окне до ответа сотруднику и приходит назад
 * при подтверждении — это только контекст модели: вызов при подтверждении
 * берётся из сохранённого, а не из trace.
 */
final class AgentService
{
	/** Сколько сообщений trace принимаем от окна. */
	public const TRACE_MAX = 40;

	public function __construct(
		private readonly AnswerService $answers,
		private readonly ModelGatewayInterface $model,
		private readonly McpGatewayInterface $mcp,
		private readonly PendingStoreInterface $pending,
		private readonly AgentHooksInterface $hooks,
		private readonly Config $config,
		private readonly ?\Closure $clock = null,
	)
	{
	}

	/**
	 * Ответ агента.
	 *
	 * @param list<array{role: string, content: string}> $trace шаги этого вопроса из окна
	 * @param array{id?: mixed, decision?: mixed}|null $confirm решение по ждущему вызову
	 * @return array{text: string, role: string, sources: list<array>, steps: list<array>, pending: ?array, trace: list<array>, finishReason: string}
	 * @throws ChatException
	 */
	public function run(Rights $rights, int $userId, ChatRequest $request, array $trace = [], ?array $confirm = null): array
	{
		AgentStatus::assert($rights, $this->config);
		$prepared = $this->answers->prepare($rights, $userId, $request);
		if($prepared->stubText !== null)
		{
			return $this->finish($prepared->stubText, $prepared, [], 'stop');
		}

		$tools = $this->tools($userId);
		$messages = $prepared->messages;
		$messages[0]['content'] .= "\n\n".ToolProtocol::instructions($tools);
		$trace = static::trace($trace, $this->config->agentResultMax);
		$steps = [];

		if($confirm !== null)
		{
			$this->resume($userId, $confirm, $tools, $trace, $steps);
		}

		for($step = 0; $step < $this->config->agentSteps; $step++)
		{
			$answer = $this->model->complete(array_merge($messages, $trace), $userId);
			$parsed = ToolProtocol::parse($answer['text']);
			if($parsed['call'] === null)
			{
				return $this->finish($parsed['text'], $prepared, $steps, $answer['finishReason'], $trace);
			}
			$trace[] = ['role' => 'assistant', 'content' => $answer['text']];
			$call = $parsed['call'];
			$tool = $tools[$call['name']] ?? null;
			if($tool === null)
			{
				$trace[] = ['role' => 'user', 'content' => 'Инструмента «'.$call['name'].'» нет. Доступны: '.implode(', ', array_keys($tools)).'. Вызови существующий или ответь сотруднику.'];
				continue;
			}
			if(ToolCatalog::needsConfirm($tool, $this->config->agentAutoRead))
			{
				$id = bin2hex(random_bytes(8));
				$this->pending->put($userId, $id, ['tool' => $tool['name'], 'title' => $tool['title'], 'arguments' => $call['arguments'], 'at' => $this->now()]);

				return [
					'text' => $parsed['text'],
					'role' => $prepared->role,
					'sources' => $prepared->sources,
					'steps' => $steps,
					'pending' => ['id' => $id, 'tool' => $tool['name'], 'title' => $tool['title'], 'readOnly' => $tool['readOnly'], 'arguments' => $call['arguments'], 'preview' => ToolProtocol::preview($call['arguments'])],
					'trace' => $trace,
					'finishReason' => 'tool',
				];
			}
			$steps[] = $this->execute($userId, $tool, $call['arguments'], $trace);
		}

		// Шаги кончились: ответ по собранному, без инструментов
		$trace[] = ['role' => 'user', 'content' => 'Шаги инструментов на этот вопрос закончились. Ответь сотруднику по уже полученным данным, без блока tool; если данных не хватает — скажи, что уточнить.'];
		$answer = $this->model->complete(array_merge($messages, $trace), $userId);

		return $this->finish(ToolProtocol::strip($answer['text']), $prepared, $steps, $answer['finishReason'], $trace);
	}

	/**
	 * Инструменты сотрудника с правилами модулей; нет подключения — ChatException mcp_auth.
	 *
	 * @return array<string, array>
	 * @throws ChatException
	 */
	public function tools(int $userId): array
	{
		try
		{
			$raw = $this->mcp->tools($userId);
		}
		catch(McpException $exception)
		{
			throw new ChatException($exception->getMessage(), $exception->errorCode);
		}
		$names = array_values(array_filter(array_map(static fn($t) => is_string($t['name'] ?? null) ? $t['name'] : null, $raw)));

		return ToolCatalog::build($raw, $this->hooks->toolRules($userId, $names));
	}

	/** Решение сотрудника по ждущему вызову: выполнить или сказать модели об отказе. */
	private function resume(int $userId, array $confirm, array $tools, array &$trace, array &$steps): void
	{
		$id = is_string($confirm['id'] ?? null) ? $confirm['id'] : '';
		$call = preg_match('/^[a-f0-9]{16}$/', $id) ? $this->pending->take($userId, $id) : null;
		if($call === null)
		{
			throw new ChatException('Это действие уже выполнено или устарело — повторите просьбу', ChatException::AGENT);
		}
		$tool = $tools[$call['tool']] ?? null;
		if($tool === null)
		{
			throw new ChatException('Инструмент «'.$call['title'].'» больше недоступен', ChatException::AGENT);
		}
		if(($confirm['decision'] ?? '') !== 'yes')
		{
			$steps[] = ['tool' => $tool['name'], 'title' => $tool['title'], 'readOnly' => $tool['readOnly'], 'status' => 'declined', 'preview' => ToolProtocol::preview($call['arguments'])];
			$trace[] = ['role' => 'user', 'content' => 'Сотрудник отказался выполнять '.$tool['name'].'. Не повторяй этот вызов; ответь или предложи другое.'];

			return;
		}
		$steps[] = $this->execute($userId, $tool, (array)$call['arguments'], $trace);
	}

	/** Вызвать инструмент: событие «до» (может запретить), сервер, событие «после». */
	private function execute(int $userId, array $tool, array $arguments, array &$trace): array
	{
		$step = ['tool' => $tool['name'], 'title' => $tool['title'], 'readOnly' => $tool['readOnly'], 'status' => 'done', 'preview' => ToolProtocol::preview($arguments)];
		$denied = $this->hooks->beforeCall($userId, $tool, $arguments);
		if($denied !== null)
		{
			$trace[] = ['role' => 'user', 'content' => 'Вызов '.$tool['name'].' запрещён на этом портале: '.$denied];

			return ['status' => 'denied', 'note' => $denied] + $step;
		}
		try
		{
			$result = $this->mcp->call($userId, $tool['name'], $arguments);
		}
		catch(McpException $exception)
		{
			if($exception->errorCode === McpException::AUTH)
			{
				throw new ChatException($exception->getMessage(), $exception->errorCode);
			}
			$result = ['text' => $exception->getMessage(), 'isError' => true];
		}
		$this->hooks->afterCall($userId, $tool, $arguments, !$result['isError']);
		$trace[] = ['role' => 'user', 'content' => ToolProtocol::result($tool['name'], $result['text'], $result['isError'], $this->config->agentResultMax)];

		return ['status' => $result['isError'] ? 'error' : 'done'] + $step;
	}

	private function finish(string $text, $prepared, array $steps, string $finishReason, array $trace = []): array
	{
		return [
			'text' => $text !== '' ? $text : 'Готово.',
			'role' => $prepared->role,
			'sources' => $prepared->sources,
			'steps' => $steps,
			'pending' => null,
			'trace' => [],
			'finishReason' => $finishReason,
		];
	}

	/**
	 * Шаги из окна: только роли assistant/user и строки, не длиннее предела.
	 *
	 * @return list<array{role: string, content: string}>
	 */
	public static function trace(mixed $raw, int $resultMax): array
	{
		$out = [];
		foreach(is_array($raw) ? $raw : [] as $message)
		{
			if(!is_array($message) || !in_array($message['role'] ?? '', ['assistant', 'user'], true) || !is_string($message['content'] ?? null))
			{
				continue;
			}
			$out[] = ['role' => $message['role'], 'content' => mb_substr($message['content'], 0, $resultMax + 2000)];
		}

		return array_slice($out, -self::TRACE_MAX);
	}

	private function now(): int
	{
		return $this->clock !== null ? ($this->clock)() : time();
	}
}
