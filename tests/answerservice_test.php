<?php declare(strict_types=1);

/**
 * Сценарий чата на подменах shef.toolsai и shef.toolsaikb: право на чат,
 * доступ к роли из библиотеки промптов («Кому доступна», 1.6.0) на КАЖДОМ
 * ответе, запомненная роль, заглушка и квота, запись
 * CRM с правами, статьи базы знаний — мягко.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Access\Rights;
use Shef\ToolsAiAssistant\Chat\AnswerService;
use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Chat\ChatRequest;
use Shef\ToolsAiAssistant\Chat\Gateway\ChoiceStoreInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\FeedbackStoreInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\KbSourceInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\ModelGatewayInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\PromptSourceInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\RateStoreInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\RecordSourceInterface;
use Shef\ToolsAiAssistant\Chat\Record;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Prompt\Roles;

/**
 * Библиотека промптов для текущего сотрудника: $open — коды, открытые ему
 * «Кому доступна» (null — все); $closed — закрыты в библиотеке уже после
 * того, как окно получило список (isAvailable() скажет «нет»).
 */
final class FakePrompts implements PromptSourceInterface
{
	public array $composed = [];
	public ?array $open = null;
	public array $closed = [];
	public array $checked = [];
	public string $template = '';
	/** Роль, которую библиотека «молча» возьмёт в compose() (роль выключили между вызовами); null — та же. */
	public ?string $substitute = null;
	public array $users = [];

	public function roles(int $userId): array
	{
		$out = [];
		foreach(Roles::raw() as $raw)
		{
			if($this->open === null || in_array($raw['code'], $this->open, true))
			{
				$out[] = ['code' => $raw['code'], 'title' => $raw['title'], 'description' => $raw['description']];
			}
		}

		return $out;
	}

	public function isAvailable(string $roleCode, int $userId): bool
	{
		$this->checked[] = $roleCode;
		$this->users[] = $userId;

		return in_array($roleCode, array_column($this->roles($userId), 'code'), true) && !in_array($roleCode, $this->closed, true);
	}

	public function compose(string $roleCode, ?int $entityTypeId, int $userId): array
	{
		$this->composed[] = [$roleCode, $entityTypeId];
		$this->users[] = $userId;

		$roleCode = $this->substitute ?? $roleCode;

		return ['role' => $roleCode, 'system' => 'ТЕКСТ РОЛИ '.$roleCode, 'examples' => ['вопрос '.$roleCode], 'template' => $this->template];
	}
}

final class FakeRecords implements RecordSourceInterface
{
	/** @var array<string, array{title: string, text: string}> «тип:id» => запись, видимая сотруднику */
	public array $visible = [];

	public function get(int $entityTypeId, int $id, int $userId): ?array
	{
		return $this->visible[$entityTypeId.':'.$id] ?? null;
	}
}

final class FakeKb implements KbSourceInterface
{
	public bool $available = true;
	public array $queries = [];

	public function isAvailable(): bool
	{
		return $this->available;
	}

	public function find(string $query, int $limit): array
	{
		$this->queries[] = [$query, $limit];

		return $this->available ? [['id' => '1', 'title' => 'Регламент', 'url' => '/notes/1/', 'snippet' => 'Отвечать за час']] : [];
	}
}

final class FakeModel implements ModelGatewayInterface
{
	public bool $stub = false;
	public bool $exceeded = false;
	public ?string $fail = null;
	/** @var list<list<array{role: string, content: string}>> */
	public array $calls = [];

	public function isStub(): bool
	{
		return $this->stub;
	}

	public function modelName(): string
	{
		return 'gpt-test';
	}

	public function complete(array $messages, int $userId = 0): array
	{
		if($this->exceeded)
		{
			// Квоту проверяет LlmService shef.toolsai до вызова провайдера
			throw new ChatException('Месячная квота ИИ исчерпана', ChatException::QUOTA);
		}
		$this->calls[] = $messages;
		if($this->fail !== null)
		{
			throw new ChatException($this->fail, ChatException::MODEL);
		}

		return ['text' => 'Ответ', 'finishReason' => 'stop'];
	}

	/** Поток: тот же ответ двумя кусками. */
	public function stream(array $messages, callable $onDelta, int $userId = 0): array
	{
		$answer = $this->complete($messages, $userId);
		$onDelta('От');
		$onDelta('вет');

		return $answer;
	}
}

final class FakeFeedback implements FeedbackStoreInterface
{
	public array $rows = [];
	public array $stamps = [];

	public function add(int $userId, array $rating): int
	{
		$this->rows[] = [$userId, $rating];

		return count($this->rows);
	}

	public function stamps(int $userId): array
	{
		return $this->stamps[$userId] ?? [];
	}

	public function putStamps(int $userId, array $stamps): void
	{
		$this->stamps[$userId] = $stamps;
	}
}

final class FakeChoices implements ChoiceStoreInterface
{
	public array $rows = [];

	public function get(int $userId, string $chatKey): ?string
	{
		return $this->rows[$userId.'|'.$chatKey] ?? null;
	}

	public function save(int $userId, string $chatKey, string $roleCode): void
	{
		$this->rows[$userId.'|'.$chatKey] = $roleCode;
	}
}

final class FakeRates implements RateStoreInterface
{
	public array $stamps = [];

	public function get(int $userId): array
	{
		return $this->stamps[$userId] ?? [];
	}

	public function put(int $userId, array $stamps): void
	{
		$this->stamps[$userId] = $stamps;
	}
}

$prompts = new FakePrompts();
$records = new FakeRecords();
$kb = new FakeKb();
$model = new FakeModel();
$choices = new FakeChoices();
$rates = new FakeRates();
$now = 1000;
$service = new AnswerService($prompts, $records, $kb, $model, $choices, $rates, new Config(kbLimit: 3, rateLimit: 100), static function() use (&$now): int { return $now; });

// Продавцу библиотека открыла четыре роли, без контроля руководителя и узких мест
$sellerRoles = [Roles::MANAGER, Roles::DEAL_QUALITY, Roles::COACH, Roles::CUSTOMER];
$prompts->open = $sellerRoles;
$seller = Rights::resolve(false, ['U1'], [['codes' => ['U1'], 'actions' => ['chat.use']]]);
$nobody = Rights::resolve(false, ['U2'], []);
$chatOnly = Rights::resolve(false, ['U3'], [['codes' => ['U3'], 'actions' => ['chat.use']]]);
$ask = static fn(string $text, ?array $record = null, string $role = ''): ChatRequest => ChatRequest::parse([['role' => 'user', 'text' => $text]], $record, $role, 10);

/** Код отказа или «ok». */
$outcome = static function(callable $call): string
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

Check::group('окно: роли и вопросы');

$info = $service->info($seller, 1, null);
Check::same('продавцу — только открытые ему библиотекой', array_column($info['roles'], 'code'), $sellerRoles);
Check::same('роль по умолчанию — помощник менеджера', $info['role'], Roles::MANAGER);
Check::same('вопросы — выбранной роли', $info['examples'], ['вопрос '.Roles::MANAGER]);
Check::same('шаблона нет — пусто', $info['template'], '');
$prompts->template = 'Разбери звонок с [клиент] за [период]';
Check::same('навык «с уточнениями» — шаблон для поля ввода (1.6.0)', $service->info($seller, 1, null)['template'], 'Разбери звонок с [клиент] за [период]');
$prompts->template = '';
Check::same('модель и база знаний', [$info['model'], $info['kb'], $info['chatKey']], ['gpt-test', true, 'general']);
Check::same('без права на чат — отказ', $outcome(static fn() => $service->info($nobody, 2, null)), ChatException::ACCESS);

Check::group('смена роли');

Check::same('роль руководителя продавцу — отказ', $outcome(static fn() => $service->chooseRole($seller, 1, new Record(2, 5), Roles::SUPERVISOR)), ChatException::ROLE);
Check::same('отказ не запомнен', $choices->rows, []);
Check::same('пустая роль — отказ', $outcome(static fn() => $service->chooseRole($seller, 1, null, ' ')), ChatException::ROLE);
$chosen = $service->chooseRole($seller, 1, new Record(2, 5), Roles::COACH);
Check::same('открытая роль — выбрана', $chosen['role'], Roles::COACH);
Check::same('запомнена на сотрудника и чат записи', $choices->rows, ['1|record:2:5' => Roles::COACH]);
$service->chooseRole($seller, 1, null, Roles::COACH, 'docs');
Check::same('справка: выбор роли — под ключом docs, общий чат не тронут (1.3.0)', [$choices->rows['1|docs'] ?? null, $choices->rows['1|general'] ?? null], [Roles::COACH, null]);
Check::same('справка: info берёт роль справки', $service->info($seller, 1, null, 'docs')['chatKey'], 'docs');
unset($choices->rows['1|docs']);
Check::same('в общем чате — по-прежнему по умолчанию', $service->info($seller, 1, null)['role'], Roles::MANAGER);
Check::same('в чате записи — запомненная', $service->info($seller, 1, new Record(2, 5))['role'], Roles::COACH);

Check::group('роль выключили между roles() и compose()');

$prompts->substitute = Roles::MANAGER;
$before = $choices->rows;
Check::same('chooseRole: библиотека взяла другую роль — отказ, выбор не запомнен', [$outcome(static fn() => $service->chooseRole($seller, 1, new Record(2, 7), Roles::COACH)), $choices->rows], [ChatException::ROLE, $before]);
Check::same('info: показывается роль, которую взяла библиотека, с её вопросами', [$service->info($seller, 1, new Record(2, 5))['role'], $service->info($seller, 1, new Record(2, 5))['examples']], [Roles::MANAGER, ['вопрос '.Roles::MANAGER]]);
$prompts->substitute = null;
Check::same('сотрудник передаётся явно во все вызовы библиотеки', array_values(array_unique($prompts->users)), [1]);

Check::group('ответ: права');

$records->visible['2:5'] = ['title' => 'Окна', 'text' => "Сделка #5: Окна\nКасаний пока нет."];
Check::same('без права на чат — отказ, модель не звали', [$outcome(static fn() => $service->answer($nobody, 2, $ask('Привет'))), count($model->calls)], [ChatException::ACCESS, 0]);
$prompts->open = [];
Check::same('чат есть, библиотека не открыла ни одной роли — отказ', $outcome(static fn() => $service->answer($chatOnly, 3, $ask('Привет'))), ChatException::NO_ROLES);
$prompts->open = $sellerRoles;
$prompts->closed = [Roles::MANAGER];
$model->calls = [];
Check::same(
	'роль закрыли в библиотеке после открытия окна — isAvailable на ответе отказывает, модель не звали',
	[$outcome(static fn() => $service->answer($seller, 1, $ask('Привет', null, Roles::MANAGER))), count($model->calls), end($prompts->checked)],
	[ChatException::ROLE, 0, Roles::MANAGER]
);
$prompts->closed = [];
Check::same(
	'роль руководителя в запросе продавца — отказ на ответе, модель не звали',
	[$outcome(static fn() => $service->answer($seller, 1, $ask('Где теряем клиента?', null, Roles::SUPERVISOR))), count($model->calls)],
	[ChatException::ROLE, 0]
);
$choices->rows['1|general'] = Roles::SUPERVISOR;
$answer = $service->answer($seller, 1, $ask('Что мешает?'));
Check::same('запомненную, но закрытую роль не берём — по умолчанию', $answer['role'], Roles::MANAGER);
Check::same('пустой вопрос — отказ', $outcome(static fn() => $service->answer($seller, 1, $ask(' '))), ChatException::EMPTY_QUESTION);

Check::group('ответ: контекст');

$model->calls = [];
$prompts->composed = [];
$answer = $service->answer($seller, 1, $ask('Что делать дальше?', ['entityTypeId' => 2, 'id' => 5]));
Check::same('ролью из запомненного выбора чата записи', $answer['role'], Roles::COACH);
Check::same('текст роли — для записи сделки', $prompts->composed, [[Roles::COACH, 2]]);
$system = $model->calls[0][0]['content'];
Check::same('модели ушли роль, запись и статья', [str_contains($system, 'ТЕКСТ РОЛИ '.Roles::COACH), str_contains($system, 'Сделка #5: Окна'), str_contains($system, '# Регламент')], [true, true, true]);
Check::same('статьи искали по вопросу с лимитом из настроек', end($kb->queries), ['Что делать дальше?', 3]);
Check::same('ответ и источники', [$answer['text'], $answer['sources']], ['Ответ', [['title' => 'Регламент', 'url' => '/notes/1/']]]);
Check::same('запись не видна в CRM — отказ', $outcome(static fn() => $service->answer($seller, 1, $ask('?', ['entityTypeId' => 2, 'id' => 6]))), ChatException::RECORD);

$kb->available = false;
$model->calls = [];
$service->answer($seller, 1, $ask('Без базы знаний?'));
Check::same('без shef.toolsaikb чат отвечает, блока статей нет', str_contains($model->calls[0][0]['content'], 'СТАТЬИ БАЗЫ ЗНАНИЙ'), false);
$kb->available = true;

Check::group('ответ: деньги');

$model->stub = true;
$model->calls = [];
$stub = $service->answer($seller, 1, $ask('Привет'));
Check::same('заглушка — подсказка без вызова модели', [$stub['text'], count($model->calls)], [AnswerService::STUB_TEXT, 0]);
$model->stub = false;
$model->exceeded = true;
Check::same('квота исчерпана — отказ без вызова модели', [$outcome(static fn() => $service->answer($seller, 1, $ask('Привет'))), count($model->calls)], [ChatException::QUOTA, 0]);
$model->exceeded = false;
$model->fail = 'Модель не ответила';
$choices->rows = [];
Check::same('сбой модели — отказ, выбор не запомнен', [$outcome(static fn() => $service->answer($seller, 1, $ask('Привет', null, Roles::CUSTOMER))), $choices->rows], [ChatException::MODEL, []]);

Check::group('частота ответов');

$model->fail = null;
$limited = new AnswerService($prompts, $records, $kb, $model, $choices, $rates, new Config(rateLimit: 2), static function() use (&$now): int { return $now; });
$rates->stamps = [];
$model->calls = [];
$limited->answer($seller, 1, $ask('Раз'));
$limited->answer($seller, 1, $ask('Два'));
Check::same('третий вопрос за минуту — отказ rate, модель не звали', [$outcome(static fn() => $limited->answer($seller, 1, $ask('Три'))), count($model->calls)], [ChatException::RATE, 2]);
Check::same('другому сотруднику — можно', $outcome(static fn() => $limited->answer(Rights::resolve(true, [], []), 9, $ask('Раз'))), 'ok');
$now += 61;
Check::same('через минуту — снова можно', $outcome(static fn() => $limited->answer($seller, 1, $ask('Четыре'))), 'ok');
$model->stub = true;
$rates->stamps = [];
$limited->answer($seller, 1, $ask('a'));
$limited->answer($seller, 1, $ask('b'));
Check::same('заглушка лимит не тратит', [$outcome(static fn() => $limited->answer($seller, 1, $ask('c'))), $rates->stamps], ['ok', []]);
$model->stub = false;

Check::group('администратор');

$model->fail = null;
$admin = Rights::resolve(true, [], []);
$prompts->open = null;
Check::same('видит все шесть ролей', count($service->info($admin, 5, null)['roles']), 6);
Check::same('отвечает ролью руководителя', $service->answer($admin, 5, $ask('Где теряем клиента?', null, Roles::SUPERVISOR))['role'], Roles::SUPERVISOR);
$prompts->open = $sellerRoles;

Check::group('поток');

$streamed = new AnswerService($prompts, $records, $kb, $model, $choices, $rates, new Config(rateLimit: 2), static function() use (&$now): int { return $now; });
$now += 120;
$rates->stamps = [];
$choices->rows = [];
$model->calls = [];
$records->visible['2:10'] = ['title' => 'Сделка', 'text' => 'история'];
$prepared = $streamed->prepare($seller, 1, $ask('Что с клиентом?', ['entityTypeId' => 2, 'id' => 10], Roles::DEAL_QUALITY));
Check::same('prepare: модель ещё не звали, роль не запомнили, отметка частоты — уже', [count($model->calls), $choices->rows, count($rates->stamps[1] ?? [])], [0, [], 1]);
$pieces = [];
$done = $streamed->stream($prepared, static function(string $d) use (&$pieces): void { $pieces[] = $d; });
Check::same('stream: куски по ходу, итог, роль запомнена', [$pieces, $done['text'], $choices->rows['1|record:2:10'] ?? null], [['От', 'вет'], 'Ответ', Roles::DEAL_QUALITY]);
$streamed->prepare($seller, 1, $ask('Ещё'));
Check::same('частота считается и для потока', $outcome(static fn() => $streamed->prepare($seller, 1, $ask('Третий'))), ChatException::RATE);
Check::same('поток: те же отказы до модели — роль', $outcome(static fn() => $streamed->prepare($seller, 1, $ask('?', null, Roles::SUPERVISOR))), ChatException::ROLE);
Check::same('поток: нет права — access', $outcome(static fn() => $streamed->prepare($nobody, 2, $ask('?'))), ChatException::ACCESS);
$model->stub = true;
$pieces = [];
$streamed->stream($streamed->prepare($seller, 3, $ask('?')), static function(string $d) use (&$pieces): void { $pieces[] = $d; });
Check::same('заглушка в потоке — одним куском', $pieces, [AnswerService::STUB_TEXT]);
$model->stub = false;

Check::group('оценка');

$feedback = new FakeFeedback();
$rating = new AnswerService($prompts, $records, $kb, $model, $choices, $rates, new Config(), static function() use (&$now): int { return $now; }, $feedback);
$good = ['rating' => 'up', 'role' => Roles::MANAGER, 'record' => ['entityTypeId' => 2, 'id' => 10], 'question' => 'Что мешает?', 'answer' => 'Нет шага', 'comment' => ''];
Check::same('оценка сохранена', [$rating->rate($seller, 1, $good), $feedback->rows[0][1]['chatKey'], $feedback->rows[0][1]['rating']], [['id' => 1], 'record:2:10', 1]);
Check::same('роль не открыта — отказ', $outcome(static fn() => $rating->rate($seller, 1, ['role' => Roles::SUPERVISOR] + $good)), ChatException::ROLE);
$prompts->closed = [Roles::MANAGER];
Check::same('роль закрыли после ответа — оценить нельзя (1.6.0, осознанно)', $outcome(static fn() => $rating->rate($seller, 1, $good)), ChatException::ROLE);
$prompts->closed = [];
Check::same('без права на чат — отказ', $outcome(static fn() => $rating->rate($nobody, 2, $good)), ChatException::ACCESS);
Check::same('мусор — отказ feedback', $outcome(static fn() => $rating->rate($seller, 1, ['rating' => '5'] + $good)), ChatException::FEEDBACK);
Check::same('без хранилища — отказ feedback', $outcome(static fn() => $service->rate($seller, 1, $good)), ChatException::FEEDBACK);
$feedback->stamps[1] = array_fill(0, \Shef\ToolsAiAssistant\Feedback\Rating::RATE_LIMIT, $now);
Check::same('слишком часто — rate', $outcome(static fn() => $rating->rate($seller, 1, $good)), ChatException::RATE);

Check::finish();
