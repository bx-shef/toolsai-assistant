<?php declare(strict_types=1);

/**
 * Подмены шлюзов чата (shef.toolsai, shef.toolsaikb, хранилища) — общие для
 * answerservice_test.php и agent_test.php.
 */

use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Chat\Gateway\ChoiceStoreInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\FeedbackStoreInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\KbSourceInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\ModelGatewayInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\PromptSourceInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\RateStoreInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\RecordSourceInterface;
use Shef\ToolsAiAssistant\Prompt\Roles;

/**
 * Библиотека промптов для текущего сотрудника: $open — коды, открытые ему
 * «Кому доступна» (null — все); $closed — закрыты в библиотеке уже после
 * того, как окно получило список (isAvailable() скажет «нет»).
 */
final class FakePrompts implements PromptSourceInterface
{
	/** Промпты меню (1.7.0): места окна — для проверки; отдаём по одному на место. */
	public array $promptPlaces = [];
	public function prompts(string $roleCode, ?int $entityTypeId, array $places, int $userId): array
	{
		$this->promptPlaces[] = $places;

		return array_map(static fn(string $place): array => ['code' => $place.'/'.$roleCode, 'title' => $place, 'text' => 'Запрос', 'type' => 'plain', 'icon' => 'o-copilot', 'iconUrl' => '', 'favorite' => false, 'group' => 'chat'], $places);
	}

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
	/** Ответы по очереди (agent_test.php); пусто — «Ответ». */
	public array $script = [];

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

		return ['text' => $this->script !== [] ? array_shift($this->script) : 'Ответ', 'finishReason' => 'stop'];
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

