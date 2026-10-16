<?php declare(strict_types=1);

/**
 * Вызов модели через shef.toolsai: журнал расхода при любом исходе, пустой
 * ответ — не успех, ошибка провайдера — с уже оплаченным расходом, причина
 * остановки по умолчанию. shef.toolsai подменён (tests/stub/toolsai.php).
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/toolsai.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Container;
use Shef\ToolsAi\Provider\Llm\LlmProviderInterface;
use Shef\ToolsAi\Provider\Llm\LlmResult;
use Shef\ToolsAi\Provider\ProviderException;
use Shef\ToolsAi\Quota\Ledger;
use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiModelGateway;

/** Модель, которая отвечает заданным или бросает заданное. */
final class FakeLlm implements LlmProviderInterface
{
	public function __construct(public string $code = 'openai', public LlmResult|\Throwable|null $next = null) {}

	public function getCode(): string
	{
		return $this->code;
	}

	public function complete(array $messages): LlmResult
	{
		if($this->next instanceof \Throwable)
		{
			throw $this->next;
		}

		return $this->next;
	}
}

$reset = static function(LlmResult|\Throwable $next, string $code = 'openai'): FakeLlm
{
	Ledger::$started = [];
	Ledger::$finished = [];
	Container::$directions = [];

	return Container::$llm = new FakeLlm($code, $next);
};
$code = static function(callable $call): string
{
	try
	{
		$call();

		return 'ok';
	}
	catch(ChatException $exception)
	{
		return $exception->errorCode;
	}
};
$messages = [['role' => 'user', 'content' => 'Привет']];

Check::group('обычный ответ');

$reset(new LlmResult('Ответ', null, 100, 20, 3500, 'stop'));
$answer = (new ToolsAiModelGateway())->complete($messages);
Check::same('ответ', $answer, ['text' => 'Ответ', 'finishReason' => 'stop']);
Check::same('направление «Чат ИИ-помощника»', Container::$directions, ['assistant']);
Check::same('журнал: начат с категорией assistant', Ledger::$started, [['sheftoolsai_assistant', 'assistant', 'openai']]);
Check::same('журнал: SUCCESS, токены и стоимость', Ledger::$finished, [[1, 'SUCCESS', 120, 3500, null]]);

Check::group('пустая причина остановки');

$reset(new LlmResult('Ответ'));
Check::same('пусто — stop', (new ToolsAiModelGateway())->complete($messages)['finishReason'], 'stop');

Check::group('пустой текст');

$reset(new LlmResult('  ', null, 10, 0, 200));
Check::same('отказ MODEL', $code(static fn() => (new ToolsAiModelGateway())->complete($messages)), ChatException::MODEL);
Check::same('журнал: ERROR, расход записан', Ledger::$finished, [[1, 'ERROR', 10, 200, 'Пустой ответ модели']]);

Check::group('ошибка провайдера');

$reset(new ProviderException('Ключ sk-123 неверный', 'bad_response', null, 7, 900));
Check::same('отказ MODEL', $code(static fn() => (new ToolsAiModelGateway())->complete($messages)), ChatException::MODEL);
Check::same('журнал: ERROR с оплаченным расходом', [Ledger::$finished[0][1], Ledger::$finished[0][2], Ledger::$finished[0][3]], ['ERROR', 7, 900]);
try
{
	(new ToolsAiModelGateway())->complete($messages);
}
catch(ChatException $exception)
{
	Check::same('текст для сотрудника — без текста провайдера', str_contains($exception->getMessage(), 'sk-123'), false);
}

$reset(new \RuntimeException('сеть'));
Check::same('прочий сбой — MODEL и ERROR в журнале', [$code(static fn() => (new ToolsAiModelGateway())->complete($messages)), Ledger::$finished[0][1]], [ChatException::MODEL, 'ERROR']);

Check::group('заглушка и квота');

$reset(new LlmResult('x'), 'echo');
$gateway = new ToolsAiModelGateway();
Check::same('echo — заглушка', [$gateway->isStub(), $gateway->modelName()], [true, 'заглушка (модель не подключена)']);
$reset(new LlmResult('x'));
Container::$exceeded = true;
$gateway = new ToolsAiModelGateway();
Check::same('квота исчерпана', $gateway->isQuotaExceeded(), true);
Check::same('название модели', $gateway->modelName(), 'gpt-test');

Check::finish();
