<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Shef\ToolsAiAssistant\Access\UserAccess;
use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Chat\ChatKey;
use Shef\ToolsAiAssistant\Chat\ChatRequest;
use Shef\ToolsAiAssistant\Chat\Greeting;
use Shef\ToolsAiAssistant\Chat\RateLimit;
use Shef\ToolsAiAssistant\Chat\PreparedAnswer;
use Shef\ToolsAiAssistant\Chat\StreamEvents;
use Shef\ToolsAiAssistant\Chat\StreamResponse;
use Shef\ToolsAiAssistant\Chat\Record;
use Shef\ToolsAiAssistant\Chat\WidgetCatalog;
use Shef\ToolsAiAssistant\Prompt\ChatPlaces;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Container;

/**
 * Окно чата: BX.ajax.runAction('shef:toolsaiassistant.chat.<действие>').
 *
 *   info   — открытые роли, выбранная роль, готовые вопросы, модель;
 *   role   — сменить роль в чате (запоминается на сотрудника и чат);
 *   stream — ответ помощника потоком (1.1.0, окно печатает текст по ходу);
 *   answer — тот же ответ одним JSON (совместимость с 1.0.0);
 *   rate   — оценка ответа «полезно / не помогло» (1.1.0);
 *   greet  — поздравление сотруднику в личку из виджета birthday (1.11.0).
 *
 * Все — только POST, авторизация и sessid (фильтры ядра). Права модуля и
 * право роли проверяет Chat\AnswerService на каждом вызове. Ответ и поток
 * отпускают сессию до вызова модели, чтобы другие вкладки портала не ждали
 * (ActionFilter\CloseSession).
 *
 * Поток: все проверки (права, роль, частота, запись) — до него, отказ —
 * обычной ошибкой ajax (JSON, как у answer); дальше действие возвращает
 * Chat\StreamResponse — события Chat\StreamEvents.
 */
class Chat extends Controller
{
	/** Фильтры: авторизация, только POST, sessid; answer — ещё и отпустить сессию до вызова модели. */
	public function configureActions(): array
	{
		$filters = [
			new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Csrf(),
		];

		return [
			'info' => ['prefilters' => $filters],
			'role' => ['prefilters' => $filters],
			'answer' => ['prefilters' => [...$filters, new ActionFilter\CloseSession()]],
			'stream' => ['prefilters' => [...$filters, new ActionFilter\CloseSession()]],
			'rate' => ['prefilters' => $filters],
			'favorite' => ['prefilters' => $filters],
			'greet' => ['prefilters' => $filters],
		];
	}

	/** Что показать в окне. $record — {entityTypeId, id} или пусто. */
	public function infoAction(array $record = [], string $context = '', string $view = ''): ?array
	{
		return $this->guarded(fn(): array => static::withPrefs(Container::getAnswerService()->info(UserAccess::current(), $this->userId(), Record::fromArray($record), ChatKey::context($context), ChatPlaces::view($view)), $this->userId())
			// 1.7.0: виджеты модулей (расширения подключит окно) и библиотека промптов shef.toolsai
			+ ['widgets' => WidgetCatalog::forClient(WidgetCatalog::collect()), 'library' => ChatPlaces::LIBRARY_URL]
			// 1.10.0: режим «Агент» — кнопка и подключение к MCP
			+ ['agent' => \Shef\ToolsAiAssistant\Agent\AgentStatus::of(UserAccess::current(), Config::current(), $this->userId())]
			// 1.11.0: ссылки виджетов на задачи и календарь сотрудника
			+ ['userId' => $this->userId()]);
	}

	/**
	 * Звёздочка роли в диалоге «Выбрать роль» (1.4.0): избранное сотрудника.
	 * Ответ — {prefs} только по открытым ролям.
	 */
	public function favoriteAction(string $role = '', string $on = 'Y'): ?array
	{
		return $this->guarded(function() use ($role, $on): array
		{
			$info = Container::getAnswerService()->info(UserAccess::current(), $this->userId(), null);
			$codes = array_column($info['roles'], 'code');
			if(!in_array($role, $codes, true))
			{
				throw new \Shef\ToolsAiAssistant\Chat\ChatException('Эта роль вам не открыта', 'role');
			}
			$prefs = \Shef\ToolsAiAssistant\Chat\RolePrefs::favorite(\Shef\ToolsAiAssistant\Chat\RolePrefsStore::load($this->userId()), $role, $on === 'Y');
			\Shef\ToolsAiAssistant\Chat\RolePrefsStore::save($this->userId(), $prefs);

			return ['prefs' => \Shef\ToolsAiAssistant\Chat\RolePrefs::filter($prefs, $codes)];
		});
	}

	/** Недавние и избранные роли — к ответу info, только открытые (1.4.0). */
	private static function withPrefs(array $info, int $userId): array
	{
		$info['prefs'] = \Shef\ToolsAiAssistant\Chat\RolePrefs::filter(\Shef\ToolsAiAssistant\Chat\RolePrefsStore::load($userId), array_column($info['roles'] ?? [], 'code'));

		return $info;
	}

	/** Сменить роль в этом чате. */
	public function roleAction(string $role = '', array $record = [], string $context = '', string $view = ''): ?array
	{
		return $this->guarded(function() use ($role, $record, $context, $view): array
		{
			$result = Container::getAnswerService()->chooseRole(UserAccess::current(), $this->userId(), Record::fromArray($record), $role, ChatKey::context($context), ChatPlaces::view($view));
			// Право проверено — в недавние (1.4.0)
			\Shef\ToolsAiAssistant\Chat\RolePrefsStore::save($this->userId(), \Shef\ToolsAiAssistant\Chat\RolePrefs::touch(\Shef\ToolsAiAssistant\Chat\RolePrefsStore::load($this->userId()), (string)$result['role']));

			return $result;
		});
	}

	/**
	 * Ответ помощника. $messages — [{role: user|assistant, text}], последний —
	 * вопрос; $role — код роли (пусто — запомненная или по умолчанию).
	 */
	public function answerAction(array $messages = [], array $record = [], string $role = '', string $context = ''): ?array
	{
		return $this->guarded(fn(): array => Container::getAnswerService()->answer(
			UserAccess::current(),
			$this->userId(),
			ChatRequest::parse($messages, $record, $role, Config::current()->history, $context)
		));
	}

	/**
	 * Ответ потоком. Параметры — как у answer. Отказ до вызова модели — ошибка
	 * ajax; после — событие error в потоке.
	 */
	public function streamAction(array $messages = [], array $record = [], string $role = '', string $context = ''): StreamResponse|null
	{
		$service = Container::getAnswerService();
		$userId = $this->userId();
		$prepared = $this->guarded(fn(): PreparedAnswer => $service->prepare(
			UserAccess::current(),
			$userId,
			ChatRequest::parse($messages, $record, $role, Config::current()->history, $context)
		));
		if(!$prepared instanceof PreparedAnswer)
		{
			return null;
		}

		return new StreamResponse(static function(callable $emit) use ($service, $prepared): void
		{
			$emit(StreamEvents::start($prepared->role, $prepared->sources));
			try
			{
				$answer = $service->stream($prepared, static fn(string $delta) => $emit(StreamEvents::delta($delta)));
				$emit(StreamEvents::finish($answer['finishReason']));
			}
			catch(ChatException $exception)
			{
				$emit(StreamEvents::error($exception->errorCode, $exception->getMessage()));
			}
			catch(\Throwable $throwable)
			{
				Container::getLogger(static::class)?->error($throwable);
				$emit(StreamEvents::error('internal', 'Чат временно недоступен — подробности в журнале модуля'));
			}
		});
	}

	/**
	 * Оценка ответа. $rating — up | down; $role — роль, в которой ответили;
	 * $question и $answer — что спросили и что ответил помощник (хранится
	 * кратко); $comment — по желанию.
	 */
	public function rateAction(string $rating = '', string $role = '', array $record = [], string $question = '', string $answer = '', string $comment = '', string $context = ''): ?array
	{
		return $this->guarded(fn(): array => Container::getAnswerService()->rate(UserAccess::current(), $this->userId(), [
			'rating' => $rating, 'role' => $role, 'record' => $record, 'question' => $question, 'answer' => $answer, 'comment' => $comment, 'context' => $context,
		]));
	}

	/**
	 * Поздравить сотрудника (1.11.0, виджет birthday) — в два шага:
	 * $confirm = N — проверить и вернуть настоящего получателя (имя с портала,
	 * а не от модели) и очищенный текст; $confirm = Y — отправить личное
	 * сообщение от текущего сотрудника. Получатель — сотрудник портала с днём
	 * рождения в пределах Greeting::BIRTHDAY_DAYS дней; не больше
	 * Greeting::RATE_LIMIT отправок в минуту. Право — «Пользоваться чатом».
	 */
	public function greetAction(string $userId = '', string $text = '', string $confirm = 'N'): ?array
	{
		return $this->guarded(function() use ($userId, $text, $confirm): array
		{
			if(!UserAccess::current()->canUseChat())
			{
				throw new ChatException('Нет доступа к чату ИИ-помощника — обратитесь к администратору портала', ChatException::ACCESS);
			}
			$greeting = Greeting::parse($this->userId(), $userId, $text);
			$messenger = Container::getMessenger();
			$recipient = $messenger->recipient($greeting->toUserId);
			if($recipient === null || !$recipient['employee'])
			{
				throw new ChatException('Сотрудник не найден или уволен', ChatException::GREETING);
			}
			if(!Greeting::isNearBirthday($recipient['birthday'], Container::now()))
			{
				throw new ChatException('У '.$recipient['name'].' сейчас не день рождения — поздравление не отправлено', ChatException::GREETING);
			}
			$result = ['name' => $recipient['name'], 'position' => $recipient['position'], 'text' => $greeting->text, 'sent' => false];
			if($confirm !== 'Y')
			{
				return $result;
			}
			$rates = Container::getGreetRates();
			$rate = RateLimit::hit($rates->get($this->userId()), Container::now()->getTimestamp(), Greeting::RATE_LIMIT);
			if(!$rate['allowed'])
			{
				throw new ChatException('Слишком много поздравлений подряд — подождите минуту', ChatException::RATE);
			}
			$rates->put($this->userId(), $rate['stamps']);
			$messenger->send($this->userId(), $greeting->toUserId, $greeting->text);

			return ['sent' => true] + $result;
		});
	}

	/** Отказ сценария — ошибкой ответа с кодом; прочий сбой — в лог, наружу общий текст. */
	private function guarded(callable $action): mixed
	{
		try
		{
			return $action();
		}
		catch(ChatException $exception)
		{
			$this->addError(new Error($exception->getMessage(), $exception->errorCode));
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(static::class)?->error($throwable);
			$this->addError(new Error('Чат временно недоступен — подробности в журнале модуля', 'internal'));
		}

		return null;
	}

	private function userId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}
}
