<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

use Shef\ToolsAiAssistant\Access\Rights;
use Shef\ToolsAiAssistant\Chat\Gateway\ChoiceStoreInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\KbSourceInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\ModelGatewayInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\PromptSourceInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\RecordSourceInterface;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Сценарий чата: что показать в окне, смена роли, ответ.
 *
 * Порядок ответа: право на чат → роль (проверка права роли на КАЖДОМ ответе,
 * RoleSelector) → заглушка/квота → запись CRM с правами сотрудника → текст
 * роли из реестра → статьи базы знаний → модель с журналом расхода →
 * запомнить роль для этого чата.
 *
 * Зависимости — интерфейсы Gateway: в портале их реализуют классы на
 * shef.toolsai и shef.toolsaikb (Container::getAnswerService()), в тестах —
 * подмены (answerservice_test.php).
 */
final class AnswerService
{
	public const STUB_TEXT = 'Модель не подключена: в shef.toolsai выбран провайдер-заглушка. Подключите модель в настройках shef.toolsai — тогда помощник ответит по истории записи и статьям базы знаний.';

	public function __construct(
		private readonly PromptSourceInterface $prompts,
		private readonly RecordSourceInterface $records,
		private readonly KbSourceInterface $kb,
		private readonly ModelGatewayInterface $model,
		private readonly ChoiceStoreInterface $choices,
		private readonly Config $config,
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
	 * Ответ помощника.
	 *
	 * @return array{text: string, role: string, sources: list<array{title: string, url: string}>, finishReason: string}
	 * @throws ChatException отказ с текстом для сотрудника
	 */
	public function answer(Rights $rights, int $userId, ChatRequest $request): array
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
			$this->choices->save($userId, $key, $role);

			return ['text' => self::STUB_TEXT, 'role' => $role, 'sources' => [], 'finishReason' => 'stop'];
		}
		if($this->model->isQuotaExceeded())
		{
			throw new ChatException('Месячная квота ИИ исчерпана — обратитесь к администратору портала', ChatException::QUOTA);
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
		$articles = $this->kb->find($request->question, $this->config->kbLimit);
		$answer = $this->model->complete(ContextBuilder::messages($composed['system'], $recordText, $articles, $request->history));
		$this->choices->save($userId, $key, $role);

		return [
			'text' => $answer['text'],
			'role' => $role,
			'sources' => ContextBuilder::sources($articles),
			'finishReason' => $answer['finishReason'],
		];
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
