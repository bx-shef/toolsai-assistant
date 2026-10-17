<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

use Shef\ToolsAiAssistant\Access\Rights;
use Shef\ToolsAiAssistant\Chat\Gateway\ChoiceStoreInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\FeedbackStoreInterface;
use Shef\ToolsAiAssistant\Feedback\Rating;
use Shef\ToolsAiAssistant\Chat\Gateway\KbSourceInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\ModelGatewayInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\PromptSourceInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\RateStoreInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\RecordSourceInterface;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Main\Constants;
use Shef\ToolsAiAssistant\Prompt\ChatPlaces;

/**
 * Сценарий чата: что показать в окне, смена роли, ответ (одним куском или
 * потоком — prepare() + stream()), оценка ответа.
 *
 * Порядок ответа: право на чат → роль (на КАЖДОМ ответе: роль из открытых
 * сотруднику — RoleSelector — и ещё раз PromptService::isAvailable: «Кому
 * доступна» в библиотеке промптов shef.toolsai 1.54.0) → заглушка → частота → запись CRM с правами сотрудника → текст
 * роли из реестра → статьи базы знаний → модель (квота и журнал расхода —
 * внутри LlmService shef.toolsai) →
 * запомнить роль для этого чата.
 *
 * Зависимости — интерфейсы Gateway: в портале их реализуют классы на
 * shef.toolsai и shef.toolsaikb (Container::getAnswerService()), в тестах —
 * подмены (answerservice_test.php).
 */
final class AnswerService
{
	public const STUB_TEXT = 'Модель не подключена: в shef.toolsai выбран провайдер-заглушка. Подключите модель в настройках shef.toolsai — тогда помощник ответит по истории записи и статьям базы знаний.';

	/** Шлюзы к shef.toolsai и shef.toolsaikb, хранилища выбора роли и отметок частоты, настройки; $clock — время для тестов (по умолчанию time()); $feedback — хранилище оценок (нет — оценки не принимаются). */
	public function __construct(
		private readonly PromptSourceInterface $prompts,
		private readonly RecordSourceInterface $records,
		private readonly KbSourceInterface $kb,
		private readonly ModelGatewayInterface $model,
		private readonly ChoiceStoreInterface $choices,
		private readonly RateStoreInterface $rates,
		private readonly Config $config,
		private readonly ?\Closure $clock = null,
		private readonly ?FeedbackStoreInterface $feedback = null,
		/** 1.7.0: подсказка модели о виджетах (WidgetCatalog::instructions()); пусто — без виджетов. */
		private readonly string $widgetHint = '',
	)
	{
	}

	/**
	 * Что показать в окне: открытые роли, выбранная, готовые вопросы, модель.
	 *
	 * template — текст навыка «с уточнениями» для поля ввода (1.6.0), иначе пусто.
	 * prompts — меню промптов в поле ввода (1.7.0): места окна — Prompt\ChatPlaces
	 * (запись — карточка, $view = list — список).
	 *
	 * @return array{roles: list<array<string, mixed>>, role: ?string, examples: list<string>, template: string, prompts: list<array<string, mixed>>, model: string, kb: bool, chatKey: string}
	 * @throws ChatException нет права на чат
	 */
	public function info(Rights $rights, int $userId, ?Record $record, string $context = '', string $view = ''): array
	{
		$this->assertChat($rights);
		$roles = $this->prompts->roles($userId);
		$key = ChatKey::of($record, $context);
		$role = RoleSelector::choose(array_column($roles, 'code'), '', $this->choices->get($userId, $key), Constants::DEFAULT_ROLE);
		$composed = $role !== null ? $this->prompts->compose($role, $record?->entityTypeId, $userId) : null;
		// Роль выключили между roles() и compose() — библиотека взяла другую: показываем её
		$role = $composed['role'] ?? null;

		return [
			'roles' => $roles,
			'role' => $role,
			'examples' => $composed['examples'] ?? [],
			'template' => $composed['template'] ?? '',
			'prompts' => $role !== null ? $this->prompts->prompts($role, $record?->entityTypeId, ChatPlaces::forWindow($record, $view, $context), $userId) : [],
			'model' => $this->model->modelName(),
			'kb' => $this->kb->isAvailable(),
			'chatKey' => $key,
		];
	}

	/**
	 * Сменить роль в чате: только открытую сотруднику; запоминается сразу.
	 *
	 * @return array{role: string, examples: list<string>, template: string, prompts: list<array<string, mixed>>}
	 * @throws ChatException нет права на чат или на роль
	 */
	public function chooseRole(Rights $rights, int $userId, ?Record $record, string $roleCode, string $context = '', string $view = ''): array
	{
		$this->assertChat($rights);
		$roleCode = strtolower(trim($roleCode));
		if($roleCode === '')
		{
			throw new ChatException('Выберите роль помощника', ChatException::ROLE);
		}
		// Прислана роль — RoleSelector вернёт её же или откажет
		$role = (string)RoleSelector::choose(array_column($this->prompts->roles($userId), 'code'), $roleCode, null, Constants::DEFAULT_ROLE);
		$composed = $this->prompts->compose($role, $record?->entityTypeId, $userId);
		// Роль выключили между roles() и compose() — библиотека молча взяла другую: не запоминаем чужую
		if(($composed['role'] ?? null) !== $role)
		{
			throw new ChatException('Эта роль помощника сейчас недоступна — выберите другую', ChatException::ROLE);
		}
		$this->choices->save($userId, ChatKey::of($record, $context), $role);

		return ['role' => $role, 'examples' => $composed['examples'], 'template' => $composed['template'], 'prompts' => $this->prompts->prompts($role, $record?->entityTypeId, ChatPlaces::forWindow($record, $view, $context), $userId)];
	}

	/**
	 * Ответ помощника одним куском (действие answer).
	 *
	 * @return array{text: string, role: string, sources: list<array{title: string, url: string}>, finishReason: string}
	 * @throws ChatException отказ с текстом для сотрудника
	 */
	public function answer(Rights $rights, int $userId, ChatRequest $request): array
	{
		$prepared = $this->prepare($rights, $userId, $request);
		$answer = $prepared->stubText !== null
			? ['text' => $prepared->stubText, 'finishReason' => 'stop']
			: $this->model->complete($prepared->messages, $userId);
		$this->choices->save($userId, $prepared->chatKey, $prepared->role);

		return ['text' => $answer['text'], 'role' => $prepared->role, 'sources' => $prepared->sources, 'finishReason' => $answer['finishReason']];
	}

	/**
	 * Все проверки до вызова модели (1.1.0: их же ждёт поток — отказ уходит
	 * обычной ошибкой ajax, а не посреди потока). Порядок: право на чат →
	 * вопрос → роль → заглушка → частота → запись CRM → текст роли → отметка
	 * частоты → статьи базы знаний. Что открыто у сотрудника (1.13.0) — строкой
	 * PageContext; $agent — лента записи короче (дальше — инструментами MCP).
	 *
	 * @throws ChatException отказ с текстом для сотрудника
	 */
	public function prepare(Rights $rights, int $userId, ChatRequest $request, bool $agent = false): PreparedAnswer
	{
		$this->assertChat($rights);
		if($request->question === '')
		{
			throw new ChatException('Напишите вопрос', ChatException::EMPTY_QUESTION);
		}
		$key = ChatKey::of($request->record, $request->context);
		$role = RoleSelector::choose(array_column($this->prompts->roles($userId), 'code'), $request->role, $this->choices->get($userId, $key), Constants::DEFAULT_ROLE);
		if($role === null)
		{
			throw new ChatException('Вам не открыта ни одна роль помощника — обратитесь к администратору портала', ChatException::NO_ROLES);
		}
		// Проверка библиотеки на каждом ответе: «Кому доступна» и «включена» — по самой роли
		if(!$this->prompts->isAvailable($role, $userId))
		{
			throw new ChatException('Эта роль помощника вам не открыта — выберите другую или обратитесь к администратору портала', ChatException::ROLE);
		}

		if($this->model->isStub())
		{
			return new PreparedAnswer($userId, $key, $role, [], [], self::STUB_TEXT);
		}
		$rate = RateLimit::hit($this->rates->get($userId), $this->now(), $this->config->rateLimit);
		if(!$rate['allowed'])
		{
			throw new ChatException('Слишком много вопросов подряд — подождите минуту', ChatException::RATE);
		}

		$recordText = null;
		$record = null;
		if($request->record !== null)
		{
			$record = $this->records->get($request->record->entityTypeId, $request->record->id, $userId);
			if($record === null)
			{
				throw new ChatException('Запись не найдена или нет доступа к ней в CRM', ChatException::RECORD);
			}
			$recordText = $agent ? ContextBuilder::compactRecord($record['text']) : $record['text'];
		}
		$page = PageContext::line($request->page, $request->record, $record);

		$composed = $this->prompts->compose($role, $request->record?->entityTypeId, $userId);
		if(($composed['role'] ?? null) !== $role)
		{
			throw new ChatException('Эта роль помощника вам не открыта — выберите другую или обратитесь к администратору портала', ChatException::ROLE);
		}
		// Отметка — до вызова модели: сбой модели тоже считается, иначе повторы в цикле обходили бы лимит
		$this->rates->put($userId, $rate['stamps']);
		$articles = $this->kb->find($request->question, $this->config->kbLimit);

		return new PreparedAnswer($userId, $key, $role, ContextBuilder::messages(trim($composed['system']."\n\n".$this->widgetHint), $recordText, $articles, $request->history, $request->files, $page), ContextBuilder::sources($articles));
	}

	/**
	 * Ответ потоком: куски — в $onDelta, как пришли от модели; заглушка — один
	 * кусок. Роль запоминается после ответа (как у answer()).
	 *
	 * @param callable(string): void $onDelta
	 * @return array{text: string, finishReason: string}
	 * @throws ChatException модель не ответила или оборвала ответ
	 */
	public function stream(PreparedAnswer $prepared, callable $onDelta): array
	{
		if($prepared->stubText !== null)
		{
			$onDelta($prepared->stubText);
			$answer = ['text' => $prepared->stubText, 'finishReason' => 'stop'];
		}
		else
		{
			$answer = $this->model->stream($prepared->messages, $onDelta, $prepared->userId);
		}
		$this->choices->save($prepared->userId, $prepared->chatKey, $prepared->role);

		return $answer;
	}

	/**
	 * Оценка ответа «полезно / не помогло» (1.1.0): право на чат, открытая
	 * роль, не больше Feedback\Rating::RATE_LIMIT оценок в минуту.
	 *
	 * Роль сверяется с библиотекой (1.6.0, «Кому доступна»): роль из оценки
	 * присылает браузер, и без проверки можно было бы записать оценку от
	 * имени закрытой роли. Цена — роль закрыли или выключили уже после
	 * ответа: оценить его нельзя (отказ ROLE). Это осознанно: закрытая роль
	 * закрыта целиком, а оценки нужны по ролям, которыми пользуются.
	 *
	 * @param array<string, mixed> $input rating, role, record, question, answer, comment
	 * @return array{id: int}
	 * @throws ChatException нет права, роль закрыта, мусор, слишком часто
	 */
	public function rate(Rights $rights, int $userId, array $input): array
	{
		$this->assertChat($rights);
		if($this->feedback === null)
		{
			throw new ChatException('Оценки сейчас не сохраняются', ChatException::FEEDBACK);
		}
		$rating = Rating::parse($input);
		if($rating === null)
		{
			throw new ChatException('Не удалось сохранить оценку: неверный запрос', ChatException::FEEDBACK);
		}
		if(!$this->prompts->isAvailable($rating['role'], $userId))
		{
			throw new ChatException('Эта роль помощника вам не открыта', ChatException::ROLE);
		}
		$rate = RateLimit::hit($this->feedback->stamps($userId), $this->now(), Rating::RATE_LIMIT);
		if(!$rate['allowed'])
		{
			throw new ChatException('Слишком много оценок подряд — подождите минуту', ChatException::RATE);
		}
		$this->feedback->putStamps($userId, $rate['stamps']);

		return ['id' => $this->feedback->add($userId, $rating)];
	}

	private function now(): int
	{
		return $this->clock !== null ? ($this->clock)() : time();
	}

	/** @throws ChatException */
	private function assertChat(Rights $rights): void
	{
		if(!$rights->canUseChat())
		{
			throw new ChatException('Нет доступа к чату ИИ-помощника — обратитесь к администратору портала', ChatException::ACCESS);
		}
	}
}
