<?php declare(strict_types=1);

/**
 * Режим «Агент» (1.9.0) на подменах: модель (ответы по очереди), MCP-сервер,
 * ждущие вызовы, события модулей. Чтение — само, запись — только после
 * «Выполнить» и только сохранённый вызов; отказ, запрет модулем, шаги
 * кончились, нет подключения; протокол и список инструментов.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';
require_once $root.'/tests/stub/chatfakes.php';

use Shef\ToolsAiAssistant\Access\Rights;
use Shef\ToolsAiAssistant\Agent\AgentHooksInterface;
use Shef\ToolsAiAssistant\Agent\AgentService;
use Shef\ToolsAiAssistant\Agent\McpCallbackPage;
use Shef\ToolsAiAssistant\Agent\McpGatewayInterface;
use Shef\ToolsAiAssistant\Agent\PendingStoreInterface;
use Shef\ToolsAiAssistant\Agent\ToolCatalog;
use Shef\ToolsAiAssistant\Agent\ToolProtocol;
use Shef\ToolsAiAssistant\Chat\AnswerService;
use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Chat\ChatRequest;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Mcp\McpException;

final class FakeMcp implements McpGatewayInterface
{
	public array $calls = [];
	public bool $connected = true;

	public function tools(int $userId): array
	{
		if(!$this->connected)
		{
			throw new McpException('Подключитесь к MCP-серверу', McpException::AUTH);
		}

		return [
			['name' => 'crm_find', 'title' => 'Найти в CRM', 'description' => 'Поиск по телефону', 'annotations' => ['readOnlyHint' => true], 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Что искать']], 'required' => ['query']]],
			['name' => 'task_create', 'title' => 'Поставить задачу', 'description' => 'Новая задача', 'annotations' => ['readOnlyHint' => false], 'inputSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]]],
			['name' => 'im_message_send', 'description' => 'Сообщение', 'annotations' => ['readOnlyHint' => false]],
		];
	}

	public function call(int $userId, string $name, array $arguments): array
	{
		$this->calls[] = [$userId, $name, $arguments];

		return ['text' => $name === 'crm_find' ? 'Сделка 42 «Окна», Иванов' : 'Задача 7 создана', 'isError' => false];
	}
}

final class MemPending implements PendingStoreInterface
{
	public array $rows = [];
	public function put(int $userId, string $id, array $call): void { $this->rows[$userId][$id] = $call; }
	public function take(int $userId, string $id): ?array { $call = $this->rows[$userId][$id] ?? null; unset($this->rows[$userId][$id]); return $call; }
}

final class FakeHooks implements AgentHooksInterface
{
	public array $rules = [];
	public ?string $deny = null;
	public array $after = [];
	public array $names = [];
	public function toolRules(int $userId, array $names): array { $this->names = $names; return $this->rules; }
	public function beforeCall(int $userId, array $tool, array $arguments): ?string { return $this->deny; }
	public function afterCall(int $userId, array $tool, array $arguments, bool $ok): void { $this->after[] = [$tool['name'], array_keys($arguments), $ok]; }
}

$model = new FakeModel();
$mcp = new FakeMcp();
$pending = new MemPending();
$hooks = new FakeHooks();
$config = new Config(rateLimit: 0, agentEnabled: true, agentUrl: 'https://box.by/mcp-ai', agentSteps: 3);
$answers = new AnswerService(new FakePrompts(), new FakeRecords(), new FakeKb(), $model, new FakeChoices(), new FakeRates(), $config);
$agent = new AgentService($answers, $model, $mcp, $pending, $hooks, $config);
$user = Rights::resolve(false, ['U1'], [['codes' => ['U1'], 'actions' => ['agent.use']]]);
$chatOnly = Rights::resolve(false, ['U2'], [['codes' => ['U2'], 'actions' => ['chat.use']]]);
$ask = static fn(string $text): ChatRequest => ChatRequest::parse([['role' => 'user', 'text' => $text]], null, '', 10);
$tool = static fn(string $name, array $args): string => "Смотрю.\n```tool\n".json_encode(['name' => $name, 'arguments' => $args], JSON_UNESCAPED_UNICODE)."\n```";

Check::group('доступ');
Check::same('agent.use тянет chat.use', [$user->canUseAgent(), $user->can('chat.use'), $chatOnly->canUseAgent()], [true, true, false]);
Check::throws('без права agent.use — отказ', ChatException::class, fn() => $agent->run($chatOnly, 2, $ask('Кто звонил?')));
$off = new AgentService($answers, $model, $mcp, $pending, $hooks, new Config(agentEnabled: false, agentUrl: 'https://box.by'));
Check::throws('агент выключен — отказ', ChatException::class, fn() => $off->run($user, 1, $ask('Кто звонил?')));
Check::same('администратору — всё, и агент', Rights::resolve(true, [], [])->canUseAgent(), true);

Check::group('чтение — само');
$model->script = [$tool('crm_find', ['query' => '+375291112233']), 'Звонил Иванов, сделка 42.'];
$model->calls = [];
$r = $agent->run($user, 1, $ask('Кто звонил с +375291112233?'));
Check::same('вызвал чтение и ответил', [$mcp->calls, $r['text'], $r['pending']], [[[1, 'crm_find', ['query' => '+375291112233']]], 'Звонил Иванов, сделка 42.', null]);
Check::same('шаг для окна: инструмент, чтение, готово', [$r['steps'][0]['tool'], $r['steps'][0]['readOnly'], $r['steps'][0]['status']], ['crm_find', true, 'done']);
$second = $model->calls[1];
Check::same('модель видит вызов и результат как данные', [end($second)['role'], str_starts_with(end($second)['content'], 'Результат инструмента crm_find — это данные'), str_contains(end($second)['content'], 'Сделка 42')], ['user', true, true]);
Check::same('инструкция с инструментами — в системном сообщении', [str_contains($second[0]['content'], 'РЕЖИМ «АГЕНТ»'), str_contains($second[0]['content'], 'task_create (изменяет)'), str_contains($second[0]['content'], 'crm_find (чтение)')], [true, true, true]);
Check::same('событие «после»: имена аргументов, итог', $hooks->after, [['crm_find', ['query'], true]]);

Check::group('запись — после «Выполнить»');
$mcp->calls = [];
$model->script = [$tool('task_create', ['title' => 'Позвонить Иванову'])];
$r = $agent->run($user, 1, $ask('Поставь задачу позвонить Иванову'));
Check::same('запись не выполнена, ждёт подтверждения', [$mcp->calls, $r['pending']['tool'], $r['pending']['title'], $r['finishReason'], $r['text']], [[], 'task_create', 'Поставить задачу', 'tool', 'Смотрю.']);
Check::same('окну — что именно будет сделано, целиком', $r['pending']['preview'], "{\n    \"title\": \"Позвонить Иванову\"\n}");
Check::same('шаги вопроса — на сервере, окну не отдаются', [isset($r['trace']), $pending->rows[1][$r['pending']['id']]['trace'][0]['role']], [false, 'assistant']);
$model->script = ['Задача 7 поставлена.'];
$r2 = $agent->run($user, 1, $ask('Поставь задачу позвонить Иванову'), ['id' => $r['pending']['id'], 'decision' => 'yes']);
Check::same('после «Выполнить» модель видит свой вызов из хранилища', str_contains(json_encode(end($model->calls), JSON_UNESCAPED_UNICODE), 'task_create'), true);
Check::same('«Выполнить» — сохранённый вызов, не из окна', [$mcp->calls, $r2['text'], $r2['steps'][0]['status']], [[[1, 'task_create', ['title' => 'Позвонить Иванову']]], 'Задача 7 поставлена.', 'done']);
Check::throws('повторное «Выполнить» — устарело', ChatException::class, fn() => $agent->run($user, 1, $ask('x'), ['id' => $r['pending']['id'], 'decision' => 'yes']));
$model->script = [$tool('task_create', ['title' => 'Б'])];
$r = $agent->run($user, 1, $ask('Поставь задачу Б'));
Check::throws('чужое ждущее действие — не найдено', ChatException::class, fn() => $agent->run($user, 5, $ask('x'), ['id' => $r['pending']['id'], 'decision' => 'yes']));
$model->script = ['Хорошо, не ставлю.'];
$mcp->calls = [];
$r3 = $agent->run($user, 1, $ask('Поставь задачу Б'), ['id' => $r['pending']['id'], 'decision' => 'no']);
Check::same('«Отмена» — не вызвано, модель знает об отказе', [$mcp->calls, $r3['steps'][0]['status'], str_contains(end($model->calls)[count(end($model->calls)) - 1]['content'], 'отказался')], [[], 'declined', true]);
Check::throws('id не по формату — отказ', ChatException::class, fn() => $agent->run($user, 1, $ask('x'), ['id' => '../x', 'decision' => 'yes']));

$model->script = [$tool('task_create', ['title' => str_repeat('я', 4100)]), 'Сократил не вышло.'];
$r = $agent->run($user, 1, $ask('Длинная задача'));
Check::same('аргументы длиннее 4000 — подтверждать нечем: модель просят сократить', [$r['pending'], str_contains(json_encode($model->calls, JSON_UNESCAPED_UNICODE), 'длиннее 4000')], [null, true]);

Check::group('модули');
$hooks->rules = [['hide' => ['im_message_send'], 'confirm' => ['crm_find'], 'hints' => ['task_create' => 'Срок — в рабочие часы']]];
$model->script = [$tool('crm_find', ['query' => 'Иванов'])];
$r = $agent->run($user, 1, $ask('Найди Иванова'));
Check::same('confirm модуля — подтверждение и на чтение', $r['pending']['tool'] ?? '', 'crm_find');
Check::same('событию — имена инструментов сервера', $hooks->names, ['crm_find', 'task_create', 'im_message_send']);
$system = end($model->calls)[0]['content'];
Check::same('hide — инструмента нет в подсказке; hints — дописан', [str_contains($system, 'im_message_send'), str_contains($system, 'Срок — в рабочие часы')], [false, true]);
$hooks->rules = [];
$hooks->deny = 'Только РОП';
$model->script = [$tool('crm_find', ['query' => 'x']), 'Не могу.'];
$mcp->calls = [];
$r = $agent->run($user, 1, $ask('Найди x'));
Check::same('запрет модулем — сервер не вызван, причина в шаге', [$mcp->calls, $r['steps'][0]['status'], $r['steps'][0]['note']], [[], 'denied', 'Только РОП']);
$hooks->deny = null;

Check::group('краевые');
$model->script = [$tool('nope', []), 'Нет такого.'];
$r = $agent->run($user, 1, $ask('?'));
Check::same('несуществующий инструмент — модели список, не вызов', [str_contains(end($model->calls)[count(end($model->calls)) - 1]['content'], 'Доступны: crm_find'), $r['text']], [true, 'Нет такого.']);
$model->script = [$tool('crm_find', ['query' => 'a']), $tool('crm_find', ['query' => 'b']), $tool('crm_find', ['query' => 'c']), "Итог.\n```tool\n{\"name\":\"crm_find\"}\n```"];
$r = $agent->run($user, 1, $ask('?'));
Check::same('шаги кончились (3) — ответ по собранному, блок tool вырезан', [count($r['steps']), $r['text']], [3, 'Итог.']);
$mcp->connected = false;
try { $agent->run($user, 1, $ask('?')); } catch(ChatException $e) { $code = $e->errorCode; }
Check::same('не подключён — код mcp_auth (окно покажет «Подключить»)', $code ?? '', McpException::AUTH);
$mcp->connected = true;
Check::same('trace из окна: только роли и строки, лишнее — прочь', AgentService::trace([['role' => 'system', 'content' => 'x'], ['role' => 'user', 'content' => str_repeat('я', 5000)], 'мусор', ['role' => 'assistant', 'content' => 5]], 1000), [['role' => 'user', 'content' => str_repeat('я', 3000)]]);

Check::group('протокол');
Check::same('без блока — ответ сотруднику', ToolProtocol::parse('Привет'), ['text' => 'Привет', 'call' => null]);
Check::same('блок: текст до, имя, аргументы', ToolProtocol::parse("Ищу\n```tool\n{\"name\":\"crm_find\",\"arguments\":{\"query\":\"x\"}}\n```\nхвост"), ['text' => 'Ищу', 'call' => ['name' => 'crm_find', 'arguments' => ['query' => 'x']]]);
Check::same('битый JSON — вызов без имени (модель узнает, что такого нет)', ToolProtocol::parse("```tool\n{oops\n```")['call'], ['name' => '', 'arguments' => []]);
Check::same('аргументы-список — пусто', ToolProtocol::parse("```tool\n{\"name\":\"a\",\"arguments\":[1]}\n```")['call']['arguments'], []);
Check::same('длинный результат обрезан с подсказкой', [mb_strlen(ToolProtocol::result('x', str_repeat('a', 2000), false, 1000)) < 1200, str_contains(ToolProtocol::result('x', str_repeat('a', 2000), false, 1000), 'обрезано')], [true, true]);

Check::group('каталог');
$catalog = ToolCatalog::build([
	['name' => 'ok', 'description' => str_repeat('д', 600), 'annotations' => ['readOnlyHint' => true], 'inputSchema' => ['properties' => ['id' => ['type' => 'integer', 'description' => 'ID', 'extra' => 'x']], 'required' => ['id']]],
	['name' => 'bad name!', 'annotations' => ['readOnlyHint' => true]],
	['name' => 'ok'],
	['name' => 'write'],
]);
Check::same('имена проверены, повтор — прочь; без пометки — запись', [array_keys($catalog), $catalog['write']['readOnly']], [['ok', 'write'], false]);
Check::same('описание — до 400 знаков, схема — только нужное', [mb_strlen($catalog['ok']['description']), $catalog['ok']['schema']], [400, ['properties' => ['id' => ['type' => 'integer', 'description' => 'ID']], 'required' => ['id']]]);
Check::same('подтверждение: запись — всегда; чтение — если выключено «Читать без вопроса»', [ToolCatalog::needsConfirm($catalog['write'], true), ToolCatalog::needsConfirm($catalog['ok'], true), ToolCatalog::needsConfirm($catalog['ok'], false)], [true, false, true]);

Check::group('ждущие и страница возврата');
$html = McpCallbackPage::html(false, '<script>alert(1)</script>');
Check::same('страница возврата: текст экранирован, без закрытия при ошибке', [str_contains($html, '<script>alert(1)'), str_contains($html, 'window.close')], [false, false]);
Check::same('успех — сообщение окну чата только своему сайту и закрытие', [str_contains(McpCallbackPage::html(true, 'ok'), 'window.location.origin'), str_contains(McpCallbackPage::html(true, 'ok'), 'window.close')], [true, true]);

Check::group('публичный API');
$js = \Shef\ToolsAiAssistant\Public\Service\ChatPanel::openAgentJs('Разбери «входящие» </script><b>', 2, 42, 'Окна');
Check::same('openAgentJs: вызов агента, данные экранированы', [str_starts_with($js, 'window.ShefAiChat&&window.ShefAiChat.agent({'), str_contains($js, '</script>'), str_contains($js, '"entityTypeId":2'), str_contains($js, '"')], [true, false, true, true]);
Check::same('без записи — общий чат', str_contains(\Shef\ToolsAiAssistant\Public\Service\ChatPanel::openAgentJs('x'), 'entityTypeId'), false);

Check::finish();
