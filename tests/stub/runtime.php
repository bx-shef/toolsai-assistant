<?php

/**
 * Заглушки ядра для тестов рантайма модуля: ORM в памяти (RoleChoiceStore),
 * контроллер и фильтры (Controller\Chat), текущий пользователь (UserAccess).
 * Поведение — минимальное, какое нужно проверке: фильтр «=ПОЛЕ», add, update.
 */

namespace Bitrix\Main\Type
{
	if(!class_exists(DateTime::class))
	{
		class DateTime
		{
		}
	}
}

namespace Bitrix\Main\DB
{
	if(!class_exists(SqlQueryException::class))
	{
		class SqlQueryException extends \RuntimeException {}
	}
}

namespace Bitrix\Main\ORM\Data
{
	if(!class_exists(DataManager::class))
	{
		/** Таблица в памяти: строки по классу, ID — по порядку. */
		abstract class DataManager
		{
			/** @var array<class-string, array<int, array>> */
			public static array $rows = [];
			/** @var array<class-string, int> сколько раз add() бросит SqlQueryException */
			public static array $failAdd = [];

			public static function getList(array $parameters = []): object
			{
				$rows = array_values(array_filter(self::$rows[static::class] ?? [], static function(array $row) use ($parameters): bool
				{
					foreach((array)($parameters['filter'] ?? []) as $key => $value)
					{
						if(($row[ltrim((string)$key, '=')] ?? null) !== $value)
						{
							return false;
						}
					}

					return true;
				}));
				if(isset($parameters['limit']))
				{
					$rows = array_slice($rows, 0, (int)$parameters['limit']);
				}

				return new class($rows)
				{
					public function __construct(private array $rows) {}
					public function fetchAll(): array { return $this->rows; }
					public function fetch(): array|false { return array_shift($this->rows) ?? false; }
				};
			}

			public static function add(array $fields): object
			{
				if((self::$failAdd[static::class] ?? 0) > 0)
				{
					self::$failAdd[static::class]--;
					throw new \Bitrix\Main\DB\SqlQueryException('Duplicate entry');
				}
				$id = count(self::$rows[static::class] ?? []) + 1;
				self::$rows[static::class][$id] = ['ID' => $id] + $fields;

				return new \Bitrix\Main\Result();
			}

			public static function update(int $id, array $fields): object
			{
				self::$rows[static::class][$id] = $fields + self::$rows[static::class][$id];

				return new \Bitrix\Main\Result();
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(HttpResponse::class))
	{
		/** Ответ ядра: заголовки копятся, writeHeaders() — отметка, что их «отправили». */
		class HttpResponse
		{
			public array $headers = [];
			public bool $headersWritten = false;

			public function __construct() {}
			public function addHeader($name, $value = '') { $this->headers[$name] = $value; return $this; }
			public function writeHeaders() { $this->headersWritten = true; }
			public function send() {}
		}
	}
}

namespace Bitrix\Main\Engine
{
	if(!class_exists(Controller::class))
	{
		class CurrentUser
		{
			public function __construct(private readonly int $id = 0, private readonly bool $admin = false) {}
			public function getId(): int { return $this->id; }
			public function isAdmin(): bool { return $this->admin; }
		}

		/** Контроллер: ошибки копятся, текущий пользователь задаётся тестом. */
		class Controller
		{
			public static ?CurrentUser $user = null;
			/** @var \Bitrix\Main\Error[] */
			private array $errors = [];

			public function addError(\Bitrix\Main\Error $error): void { $this->errors[] = $error; }
			public function getErrors(): array { return $this->errors; }
			public function getCurrentUser(): ?CurrentUser { return static::$user; }
		}
	}
}

namespace Bitrix\Main\Engine\ActionFilter
{
	if(!class_exists(Authentication::class))
	{
		class Base {}
		class Authentication extends Base {}
		class Csrf extends Base {}
		class CloseSession extends Base {}
		class HttpMethod extends Base
		{
			public const METHOD_POST = 'POST';
			public function __construct(public readonly array $methods = []) {}
		}
	}
}

namespace
{
	if(!class_exists(CUser::class))
	{
		class CUser
		{
			public function __construct(private readonly int $id = 0, private readonly bool $admin = false) {}
			public function IsAuthorized(): bool { return $this->id > 0; }
			public function GetID(): int { return $this->id; }
			public function IsAdmin(): bool { return $this->admin; }
		}
	}
}
