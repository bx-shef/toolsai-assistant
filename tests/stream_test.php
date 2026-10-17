<?php declare(strict_types=1);

/**
 * События потока ответа (Chat\StreamEvents) и ответ потоком
 * (Chat\StreamResponse): формат, который читает chat.js, — одна строка
 * «data: {json}» на событие, перевод строки внутри текста не рвёт событие;
 * заголовки — text/event-stream без буфера nginx; send() пишет заголовки и
 * печатает куски по мере прихода.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/runtime.php';
require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Chat\StreamEvents;
use Shef\ToolsAiAssistant\Chat\StreamResponse;

Check::group('события');

$body = StreamEvents::start('shef.toolsaiassistant:manager', [['title' => 'Регламент', 'url' => '/notes/1/']])
	.StreamEvents::delta("Строка 1\n\nСтрока 2 </script>")
	.StreamEvents::finish('')
	.StreamEvents::error('model', 'Модель не ответила');
$events = StreamEvents::parse($body);
Check::same('четыре события по порядку', array_column($events, 'type'), ['start', 'delta', 'finish', 'error']);
Check::same('перевод строки в тексте не рвёт событие', $events[1]['text'], "Строка 1\n\nСтрока 2 </script>");
Check::same('пустая причина — stop', $events[2]['finishReason'], 'stop');
Check::same('start: роль и статьи', [$events[0]['role'], $events[0]['sources'][0]['url']], ['shef.toolsaiassistant:manager', '/notes/1/']);
Check::same('каждое событие — одна строка data:', count(array_filter(explode("\n", $body), static fn(string $l): bool => $l !== '')), 4);
Check::same('битый UTF-8 не роняет событие', StreamEvents::parse(StreamEvents::delta("a\xB1b"))[0]['type'] ?? null, 'delta');

Check::group('ответ потоком');

$response = new StreamResponse(static function(callable $emit): void
{
	$emit(StreamEvents::delta('раз'));
	$emit(StreamEvents::delta('два'));
});
Check::same('заголовки потока', [$response->headers['Content-Type'], $response->headers['X-Accel-Buffering']], ['text/event-stream; charset=utf-8', 'no']);
// send() сбрасывает все буферы — печать ловим в отдельном процессе
$script = sprintf('require %s; require %s; ob_start(); echo "мусор из буфера"; $r = new %s(static function(callable $emit): void { $emit(%s::delta("раз")); $emit(%s::delta("два")); }); $r->send(); echo $r->headersWritten ? "|headers" : "|none";',
	var_export($root.'/tests/stub/runtime.php', true), var_export($root.'/tests/stub/autoload.php', true), StreamResponse::class, StreamEvents::class, StreamEvents::class);
$printed = (string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
Check::same('send(): буфер сброшен, куски напечатаны по порядку, заголовки отправлены', [str_contains($printed, 'мусор'), array_column(StreamEvents::parse($printed), 'text'), str_ends_with($printed, '|headers')], [false, ['раз', 'два'], true]);

Check::finish();
