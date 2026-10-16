<?php declare(strict_types=1);

/**
 * Вызов модели через публичный LlmService shef.toolsai 1.50.0: категория
 * assistant, отказы сервиса — в ChatException с кодом и текстом для
 * сотрудника (без текста провайдера), пустая причина — stop, заглушка — по
 * пустому названию модели. Квота и журнал расхода — внутри сервиса
 * (проверяются тестами shef.toolsai). shef.toolsai подменён (tests/stub/toolsai.php).
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/toolsai.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Public\Service\LlmResponse;
use Shef\ToolsAi\Public\Service\LlmService;
use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiModelGateway;

$fail = static function(LlmResponse $next): array
{
	LlmService::$next = $next;
	try
	{
		(new ToolsAiModelGateway())->complete([['role' => 'user', 'content' => '?']], 5);

		return ['ok', ''];
	}
	catch(ChatException $exception)
	{
		return [$exception->errorCode, $exception->getMessage()];
	}
};

Check::group('обычный ответ');

LlmService::$next = new LlmResponse('Ответ', 'length', 3500, 120);
LlmService::$calls = [];
Check::same('ответ и причина', (new ToolsAiModelGateway())->complete([['role' => 'user', 'content' => '?']], 5), ['text' => 'Ответ', 'finishReason' => 'length']);
Check::same('категория assistant, сотрудник — в сервис', LlmService::$calls, [['assistant', 1, 5]]);
LlmService::$next = new LlmResponse('Ответ');
Check::same('пустая причина — stop', (new ToolsAiModelGateway())->complete([])['finishReason'], 'stop');
LlmService::$calls = [];
(new ToolsAiModelGateway())->complete([]);
Check::same('без сотрудника — null', LlmService::$calls[0][2], null);

Check::group('отказы сервиса');

Check::same('квота — QUOTA', $fail(LlmResponse::failed(LlmService::ERROR_QUOTA, 'quota'))[0], ChatException::QUOTA);
Check::same('пустой ответ — MODEL', $fail(LlmResponse::failed(LlmService::ERROR_EMPTY, 'empty'))[0], ChatException::MODEL);
$provider = $fail(LlmResponse::failed(LlmService::ERROR_PROVIDER, 'Ключ sk-123 неверный'));
Check::same('провайдер — MODEL, без текста провайдера', [$provider[0], str_contains($provider[1], 'sk-123')], [ChatException::MODEL, false]);
Check::same('прочее — MODEL', $fail(LlmResponse::failed(LlmService::ERROR_INTERNAL, 'x'))[0], ChatException::MODEL);
Check::same('у каждого кода сервиса, кроме внутренних, — текст', array_keys(ToolsAiModelGateway::ERRORS), [LlmService::ERROR_QUOTA, LlmService::ERROR_NO_MODEL, LlmService::ERROR_EMPTY, LlmService::ERROR_PROVIDER]);

Check::group('заглушка');

LlmService::$model = '';
$gateway = new ToolsAiModelGateway();
Check::same('пустое название — заглушка', [$gateway->isStub(), $gateway->modelName()], [true, 'заглушка (модель не подключена)']);
LlmService::$model = 'deepseek-chat';
$gateway = new ToolsAiModelGateway();
Check::same('модель подключена', [$gateway->isStub(), $gateway->modelName()], [false, 'deepseek-chat']);

Check::finish();
