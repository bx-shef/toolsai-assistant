<?php declare(strict_types=1);

/**
 * Контроллер окна чата: права — из UserAccess текущего пользователя, отказ
 * сценария — ошибкой с кодом, прочий сбой — общим текстом без подробностей,
 * фильтры — POST, авторизация, sessid. Ядро и сценарий подменены.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/runtime.php';
require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Engine\ActionFilter;
use Shef\ToolsAiAssistant\Chat\AnswerService;
use Shef\ToolsAiAssistant\Chat\Gateway\ChoiceStoreInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\FeedbackStoreInterface;
use Shef\ToolsAiAssistant\Chat\StreamEvents;
use Shef\ToolsAiAssistant\Chat\StreamResponse;
use Shef\ToolsAiAssistant\Chat\Gateway\KbSourceInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\ModelGatewayInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\PromptSourceInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\RateStoreInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\RecordSourceInterface;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Container;
use Shef\ToolsAiAssistant\Controller\Chat;
use Shef\ToolsAiAssistant\Prompt\Roles;

$broken = false;
$service = new AnswerService(
	new class($broken) implements PromptSourceInterface
	{
		public function __construct(private bool &$broken) {}
		public function roles(?array $allowed): array
		{
			if($this->broken)
			{
				throw new \RuntimeException('SQL: таблица shef_toolsai_prompt недоступна');
			}

			return [['code' => Roles::MANAGER, 'title' => 'Помощник менеджера', 'description' => '']];
		}
		public function compose(string $roleCode, ?int $entityTypeId, ?array $allowed): array { return ['system' => 'роль', 'examples' => []]; }
	},
	new class implements RecordSourceInterface { public function get(int $entityTypeId, int $id, int $userId): ?array { return null; } },
	new class implements KbSourceInterface { public function isAvailable(): bool { return false; } public function find(string $query, int $limit): array { return []; } },
	new class implements ModelGatewayInterface
	{
		public function isStub(): bool { return false; }
		public function modelName(): string { return 'm'; }
		public bool $breakStream = false;
		public function complete(array $messages, int $userId = 0): array { return ['text' => 'Ответ', 'finishReason' => 'stop']; }
		public function stream(array $messages, callable $onDelta, int $userId = 0): array
		{
			$onDelta('От');
			if($this->breakStream)
			{
				throw new \Shef\ToolsAiAssistant\Chat\ChatException('Ответ модели оборвался — спросите ещё раз', 'model');
			}
			$onDelta('вет');

			return ['text' => 'Ответ', 'finishReason' => 'length'];
		}
	},
	new class implements ChoiceStoreInterface { public function get(int $userId, string $chatKey): ?string { return null; } public function save(int $userId, string $chatKey, string $roleCode): void {} },
	new class implements RateStoreInterface { public function get(int $userId): array { return []; } public function put(int $userId, array $stamps): void {} },
	new Config(),
	null,
	$feedback = new class implements FeedbackStoreInterface
	{
		public array $rows = [];
		public function add(int $userId, array $rating): int { $this->rows[] = [$userId, $rating]; return count($this->rows); }
		public function stamps(int $userId): array { return []; }
		public function putStamps(int $userId, array $stamps): void {}
	},
);
Container::setAnswerService($service);

$errors = static fn(Chat $c): array => array_map(static fn($e): array => [$e->getCode(), $e->getMessage()], $c->getErrors());

Check::group('фильтры');

$config = (new Chat())->configureActions();
Check::same('шесть действий', array_keys($config), ['info', 'role', 'answer', 'stream', 'rate', 'favorite']);
$classes = static fn(array $filters): array => array_map(static fn(object $f): string => (new \ReflectionClass($f))->getShortName(), $filters);
Check::same('info: авторизация, POST, sessid', $classes($config['info']['prefilters']), ['Authentication', 'HttpMethod', 'Csrf']);
Check::same('answer: ещё и отпустить сессию', $classes($config['answer']['prefilters']), ['Authentication', 'HttpMethod', 'Csrf', 'CloseSession']);
Check::same('stream: как answer — с CloseSession', $classes($config['stream']['prefilters']), ['Authentication', 'HttpMethod', 'Csrf', 'CloseSession']);
Check::same('rate: авторизация, POST, sessid', $classes($config['rate']['prefilters']), ['Authentication', 'HttpMethod', 'Csrf']);
Check::same('только POST', $config['role']['prefilters'][1]->methods, [ActionFilter\HttpMethod::METHOD_POST]);

Check::group('права — из UserAccess');

$GLOBALS['USER'] = new CUser(5, true);
$chat = new Chat();
$answer = $chat->answerAction([['role' => 'user', 'text' => 'Что мешает?']]);
Check::same('администратор получает ответ', [$answer['text'] ?? null, $answer['role'] ?? null, $errors($chat)], ['Ответ', Roles::MANAGER, []]);

$GLOBALS['USER'] = new CUser(0, false);
$chat = new Chat();
Check::same('не авторизован — null и ошибка access', [$chat->answerAction([['role' => 'user', 'text' => '?']]), $errors($chat)[0][0] ?? null], [null, 'access']);

Check::group('ошибки');

$GLOBALS['USER'] = new CUser(5, true);
$chat = new Chat();
$chat->roleAction('shef.toolsaiassistant:nope');
Check::same('отказ сценария — код и текст для сотрудника', $errors($chat)[0][0] ?? null, 'role');
$chat = new Chat();
$chat->answerAction([]);
Check::same('пустой вопрос — empty_question', $errors($chat)[0][0] ?? null, 'empty_question');

$broken = true;
$chat = new Chat();
Check::same('сбой — null, код internal, без текста SQL', [$chat->infoAction(), $errors($chat)[0][0] ?? null, str_contains($errors($chat)[0][1] ?? '', 'SQL')], [null, 'internal', false]);

Check::group('поток');

/** Прогнать поток ответа и разобрать события. */
$play = static function(StreamResponse $response): array
{
	$out = '';
	$producer = (new \ReflectionProperty($response, 'producer'))->getValue($response);
	$producer(static function(string $chunk) use (&$out): void { $out .= $chunk; });

	return StreamEvents::parse($out);
};
$broken = false;
$GLOBALS['USER'] = new CUser(5, true);
$chat = new Chat();
$response = $chat->streamAction([['role' => 'user', 'text' => 'Что мешает?']]);
Check::same('поток — StreamResponse, заголовок text/event-stream', [$response instanceof StreamResponse, $response?->headers['Content-Type'] ?? null], [true, 'text/event-stream; charset=utf-8']);
Check::same('события: start, куски, finish', array_map(static fn(array $e): string => $e['type'].($e['text'] ?? '').($e['finishReason'] ?? ''), $play($response)), ['start', 'deltaОт', 'deltaвет', 'finishlength']);

$GLOBALS['USER'] = new CUser(0, false);
$chat = new Chat();
Check::same('нет права — до потока: null и ошибка access (JSON)', [$chat->streamAction([['role' => 'user', 'text' => '?']]), $errors($chat)[0][0] ?? null], [null, 'access']);

$GLOBALS['USER'] = new CUser(5, true);
$chat = new Chat();
Check::same('пустой вопрос — до потока', [$chat->streamAction([]), $errors($chat)[0][0] ?? null], [null, 'empty_question']);

$modelGateway = (new \ReflectionProperty($service, 'model'))->getValue($service);
$modelGateway->breakStream = true;
$events = $play((new Chat())->streamAction([['role' => 'user', 'text' => 'Что мешает?']]));
Check::same('обрыв посреди ответа — событие error с текстом', [array_column($events, 'type'), end($events)['message'] ?? ''], [['start', 'delta', 'error'], 'Ответ модели оборвался — спросите ещё раз']);
$modelGateway->breakStream = false;

Check::group('оценка');

\Bitrix\Main\Engine\Controller::$user = new \Bitrix\Main\Engine\CurrentUser(5, true);
$chat = new Chat();
$rated = $chat->rateAction('up', Roles::MANAGER, ['entityTypeId' => 2, 'id' => 7], 'Что мешает?', 'Нет следующего шага', 'в точку');
Check::same('оценка сохранена', [$rated, $feedback->rows[0][0] ?? null, $feedback->rows[0][1]['chatKey'] ?? null, $feedback->rows[0][1]['rating'] ?? null], [['id' => 1], 5, 'record:2:7', 1]);
$chat = new Chat();
Check::same('мусор — ошибка feedback', [$chat->rateAction('meh', Roles::MANAGER, [], 'q', 'a'), $errors($chat)[0][0] ?? null], [null, 'feedback']);
$GLOBALS['USER'] = new CUser(0, false);
$chat = new Chat();
Check::same('без права — access', [$chat->rateAction('up', Roles::MANAGER, [], 'q', 'a'), $errors($chat)[0][0] ?? null], [null, 'access']);

Check::finish();
