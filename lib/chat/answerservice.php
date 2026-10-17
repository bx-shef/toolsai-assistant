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

/**
 * Сценарий чата: что показать в окне, смена роли, ответ (одним куском или
 * потоком — prepare() + stream()), оценка ответа.
 *
 * Порядок ответа: право на чат → роль (проверка права роли на КАЖДОМ ответе,
 * RoleSelector) → заглушка → частота → запись CRM с правами сотрудника → текст
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
	)
	{
	}

	/**
	 * Что показать в окне: открытые роли, выбранная, готовые вопросы, модель.
	 *
	 * @return array{roles: list<array{code: string, title: string, description: string}>, role: ?string, examples: list<string>, model: string, kb: bool, chatKey: string}
	 * @throws ChatException нет права на чат
	 */
	public function info(Rights $rights, int $userId, ?Record $record): array
	{
		$this->assertChat($rights);
		$roles = $this->prompts->roles($rights->allowedRoles());
		$key = ChatKey::of($record);
		$role = RoleSelector::choose(array_column($roles, 'code'), '', $this->choices->get($userId, $key), Constants::DEFAULT_ROLE);

		return [
			'roles' => $roles,
			'role' => $role,
			'examples' => $role !== null ? $this->prompts->compose($role, $record?->entityTypeId, $rights->allowedRoles())['examples'] : [],
			'model' => $this->model->modelName(),
			'kb' => $this->kb->isAvailable(),
			'chatKey' => $key,
		];
	}

	/**
	 * Сменить роль в чате: только открытую сотруднику; запоминается сразу.
	 *
	 * @return array{role: string, examples: list<string>}
	 * @throws ChatException нет права на чат или на роль
	 */
	public function chooseRole(Rights $rights, int $userId, ?Record $record, string $roleCode): array
	{
		$this->assertChat($rights);
		$roleCode = strtolower(trim($roleCode));
		if($roleCode === '')
		{
			throw new ChatException('Выберите роль помощника', ChatException::ROLE);
		}
		// Прислана роль — RoleSelector вернёт её же или откажет
		$role = (string)RoleSelector::choose(array_column($this->prompts->roles($rights->allowedRoles()), 'code'), $roleCode, null, Constants::DEFAULT_ROLE);
		$this->choices->save($userId, ChatKey::of($record), $role);

		return ['role' => $role, 'examples' => $this->prompts->compose($role, $record?->entityTypeId, $rights->allowedRoles())['examples']];
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
	 * частоты → статьи базы знаний.
	 *
	 * @throws ChatException отказ с текстом для сотрудника
	 */
	public function prepare(Rights $rights, int $userId, ChatRequest $request): PreparedAnswer
	{
		$this->assertChat($rights);
		if($request->question === '')
		{
			throw new ChatException('Напишите вопрос', ChatException::EMPTY_QUESTION);
		}
		$allowed = $rights->allowedRoles();
		$key = ChatKey::of($request->record);
		$role = RoleSelector::choose(array_column($this->prompts->roles($allowed), 'code'), $request->role, $this->choices->get($userId, $key), Constants::DEFAULT_ROLE);
		if($role === null)
		{
			throw new ChatException('Вам не открыта ни одна роль помощника — обратитесь к администратору портала', ChatException::NO_ROLES);
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
		if($request->record !== null)
		{
			$record = $this->records->get($request->record->entityTypeId, $request->record->id, $userId);
			if($record === null)
			{
				throw new ChatException('Запись не найдена или нет доступа к ней в CRM', ChatException::RECORD);
			}
			$recordText = $record['text'];
		}

		$composed = $this->prompts->compose($role, $request->record?->entityTypeId, $allowed);
		// Отметка — до вызова модели: сбой модели тоже считается, иначе повторы в цикле обходили бы лимит
		$this->rates->put($userId, $rate['stamps']);
		$articles = $this->kb->find($request->question, $this->config->kbLimit);

		return new PreparedAnswer($userId, $key, $role, ContextBuilder::messages($composed['system'], $recordText, $articles, $request->history), ContextBuilder::sources($articles));
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
		if(!$rights->canRole($rating['role']))
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
