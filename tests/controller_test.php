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
		public function isQuotaExceeded(): bool { return false; }
		public function modelName(): string { return 'm'; }
		public function complete(array $messages): array { return ['text' => 'Ответ', 'finishReason' => 'stop']; }
	},
	new class implements ChoiceStoreInterface { public function get(int $userId, string $chatKey): ?string { return null; } public function save(int $userId, string $chatKey, string $roleCode): void {} },
	new class implements RateStoreInterface { public function get(int $userId): array { return []; } public function put(int $userId, array $stamps): void {} },
	new Config(),
);
Container::setAnswerService($service);

$errors = static fn(Chat $c): array => array_map(static fn($e): array => [$e->getCode(), $e->getMessage()], $c->getErrors());

Check::group('фильтры');

$config = (new Chat())->configureActions();
Check::same('три действия', array_keys($config), ['info', 'role', 'answer']);
$classes = static fn(array $filters): array => array_map(static fn(object $f): string => (new \ReflectionClass($f))->getShortName(), $filters);
Check::same('info: авторизация, POST, sessid', $classes($config['info']['prefilters']), ['Authentication', 'HttpMethod', 'Csrf']);
Check::same('answer: ещё и отпустить сессию', $classes($config['answer']['prefilters']), ['Authentication', 'HttpMethod', 'Csrf', 'CloseSession']);
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

Check::finish();
