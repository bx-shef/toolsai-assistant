<?php

/**
 * Заглушки ядра Битрикса для тестов — ровно столько, сколько нужно, чтобы
 * подключить настоящие чистые классы модуля, include.php, options_conf.php и
 * обработчик события. Логику модуля здесь не повторяем.
 *
 * Основа — заглушки shef.toolsai (tests/stub/bitrix.php там): тот же формат,
 * те же сигнатуры, только нужные этому модулю классы.
 *
 * Блочный синтаксис namespace: классы в нескольких пространствах имён.
 */

namespace Bitrix\Main
{
	if(!class_exists(SystemException::class))
	{
		class SystemException extends \Exception {}
		class ArgumentException extends SystemException {}
		class ArgumentNullException extends ArgumentException {}
		class ObjectException extends SystemException {}
	}
}

namespace Bitrix\Main\Localization
{
	if(!class_exists(Loc::class))
	{
		/**
		 * Сообщения языковых файлов. Тестам важно не что вернётся, а что
		 * вызов не падает: проверять перевод здесь нечего, он уедет на портал.
		 */
		class Loc
		{
			/**
			 * Сообщения, которые тест загрузил сам. Пусто — getMessage()
			 * отдаёт код, как раньше: большинству тестов перевод не важен.
			 * Загружены — отдаёт перевод с подстановкой, а незнакомый код —
			 * null, как ядро: тест, которому важны подписи, увидит пропуск.
			 *
			 * @var array<string, string>
			 */
			public static array $messages = [];

			public static function loadMessages(string $file): void {}

			/** Подгрузить настоящий языковой файл модуля. */
			public static function loadLangFile(string $path): void
			{
				$MESS = [];
				include $path;
				static::$messages = array_merge(static::$messages, $MESS);
			}

			public static function getMessage(string $code, ?array $replace = null, ?string $language = null): ?string
			{
				if(empty(static::$messages))
				{
					return $code;
				}

				if(!isset(static::$messages[$code]))
				{
					return null;
				}

				return strtr(static::$messages[$code], $replace ?? []);
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(Loader::class))
	{
		class LoaderException extends SystemException {}

		class Loader
		{
			public const MODULE_NOT_FOUND = 0;
			public const MODULE_INSTALLED = 1;
			public const MODULE_DEMO = 2;
			public const MODULE_DEMO_EXPIRED = 3;

			/**
			 * Куда Loader::getLocal() «нашёл» файл. Пусто — не нашёл, как на
			 * портале без модуля.
			 *
			 * @var array<string, string>
			 */
			public static array $local = [];

			/** @var array<string, string> что зарегистрировал autoload.php */
			public static array $namespaces = [];

			/**
			 * Модули, которых «нет на портале»: includeModule() отвечает false.
			 *
			 * @var string[]
			 */
			public static array $missing = [];

			public static function includeModule(string $moduleName): bool
			{
				return !in_array($moduleName, static::$missing, true);
			}

			/**
			 * Что делает подключение модуля — например, shef.options объявляет
			 * свои _log(). Задаёт тест, которому это важно.
			 *
			 * @var array<string, callable>
			 */
			public static array $onInclude = [];

			public static function includeSharewareModule(string $moduleName): int
			{
				if(isset(static::$onInclude[$moduleName]))
				{
					(static::$onInclude[$moduleName])();
				}

				return static::MODULE_INSTALLED;
			}

			public static function getDocumentRoot(): string
			{
				return Application::getDocumentRoot();
			}

			public static function getLocal(string $path, ?string $root = null): string|false
			{
				return static::$local[$path] ?? false;
			}

			public static function registerNamespace(string $namespace, string $path): void
			{
				static::$namespaces[$namespace] = $path;
			}

			public static function registerAutoLoadClasses(?string $moduleName, array $classes): void
			{
			}
		}
	}
}

namespace Bitrix\Main\Config
{
	if(!class_exists(Option::class))
	{
		/**
		 * Настройки модуля. Важно не как они хранятся, а что приходит обратно:
		 * значение опции — всегда строка из формы, и разбирать её приходится
		 * коду модуля.
		 */
		class Option
		{
			/** @var array<string, array<string, mixed>> */
			public static array $values = [];

			public static function set(string $moduleId, string $name, mixed $value): void
			{
				static::$values[$moduleId][$name] = $value;
			}

			public static function forget(string $moduleId, string $name): void
			{
				unset(static::$values[$moduleId][$name]);
			}

			public static function get(string $moduleId, string $name, mixed $default = '', mixed $siteId = false): mixed
			{
				return static::$values[$moduleId][$name] ?? $default;
			}

			/** Настройки модуля: целиком или одну по ['name' => …] — как ядро. */
			public static function delete(string $moduleId, array $filter = []): void
			{
				if(isset($filter['name']))
				{
					unset(static::$values[$moduleId][$filter['name']]);

					return;
				}
				unset(static::$values[$moduleId]);
			}
		}
	}

	if(!class_exists(Configuration::class))
	{
		/**
		 * Содержимое .settings.php. Отдаёт не весь ключ, а его value — ровно
		 * так это читает autoload.php модуля. Наполняет массив тот тест,
		 * которому он нужен: заглушка не знает, где лежит файл.
		 */
		class Configuration
		{
			/** @var array<string, array> */
			public static array $settings = [];

			public static function getInstance(?string $moduleId = null): static
			{
				return new static();
			}

			/**
			 * Настройки ядра — /bitrix/.settings.php и .settings_extra.php.
			 * Пусто, пока тест не положит своё: Composer на стенде нет,
			 * каталог логов — по умолчанию.
			 *
			 * @var array<string, mixed>
			 */
			public static array $values = [];

			public static function getValue(string $name): mixed
			{
				return static::$values[$name] ?? null;
			}

			public function get(string $key): mixed
			{
				return static::$settings[$key]['value'] ?? null;
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(Error::class))
	{
		/**
		 * Result и Error — то, чем модуль возвращает «получилось / не
		 * получилось» вместе с данными. Поведение простое, но важное:
		 * isSuccess() определяется НАЛИЧИЕМ ошибок, а не отдельным флагом.
		 */
		class Error
		{
			public function __construct(
				private readonly string $message = '',
				private readonly int|string $code = 0,
				private readonly mixed $customData = null
			) {}

			public function getMessage(): string
			{
				return $this->message;
			}

			public function getCode(): int|string
			{
				return $this->code;
			}

			public function getCustomData(): mixed
			{
				return $this->customData;
			}

			public function __toString(): string
			{
				return $this->message;
			}

			public function jsonSerialize(): array
			{
				return [
					'message' => $this->getMessage(),
					'code' => $this->getCode(),
					'customData' => $this->getCustomData(),
				];
			}
		}

		/**
		 * Коллекция ошибок — ровно те методы, которыми по ней ходит
		 * BitrixResultStrategy: перемотка, текущий, количество.
		 */
		class ErrorCollection
		{
			private int $position = 0;

			/** @param Error[] $errors */
			public function __construct(private array $errors = []) {}

			public function rewind(): void
			{
				$this->position = 0;
			}

			public function current(): ?Error
			{
				return $this->errors[$this->position] ?? null;
			}

			public function count(): int
			{
				return count($this->errors);
			}
		}

		class Result
		{
			/** @var Error[] */
			protected array $errors = [];
			protected array $data = [];

			public function isSuccess(): bool
			{
				return empty($this->errors);
			}

			public function addError(Error $error): static
			{
				$this->errors[] = $error;
				return $this;
			}

			/** @param Error[] $errors */
			public function addErrors(array $errors): static
			{
				foreach($errors as $error)
				{
					$this->errors[] = $error;
				}

				return $this;
			}

			/** @return Error[] */
			public function getErrors(): array
			{
				return $this->errors;
			}

			/** @return string[] */
			public function getErrorMessages(): array
			{
				return array_map(
					static fn(Error $error): string => $error->getMessage(),
					$this->errors
				);
			}

			/**
			 * Данные кладутся как есть: ссылка внутри массива остаётся
			 * ссылкой, и это не мелочь — Pid::removeByGroup() наполняет
			 * список УЖЕ ПОСЛЕ вызова setData().
			 */
			public function setData(array $data): static
			{
				$this->data = $data;
				return $this;
			}

			public function getData(): array
			{
				return $this->data;
			}

			public function getErrorCollection(): ErrorCollection
			{
				return new ErrorCollection(array_values($this->errors));
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(EventResult::class))
	{
		class Event
		{
			public function __construct(
				public readonly string $moduleId = '',
				public readonly string $type = '',
				public readonly array $parameters = []
			) {}

			public function send(): void {}

			public function getParameter(string $key): mixed
			{
				return $this->parameters[$key] ?? null;
			}
		}

		class EventResult
		{
			public const UNDEFINED = 0;
			public const SUCCESS = 1;
			public const ERROR = 2;

			public function __construct(
				private readonly int $type,
				private readonly mixed $parameters = null,
				private readonly ?string $moduleId = null
			) {}

			public function getType(): int
			{
				return $this->type;
			}

			public function getParameters(): mixed
			{
				return $this->parameters;
			}

			public function getModuleId(): ?string
			{
				return $this->moduleId;
			}
		}

		/** Регистрация обработчиков: запоминаем, что сняли. */
		class EventManager
		{
			/** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}> */
			public static array $unregistered = [];

			/** @var list<array> */
			public static array $registered = [];

			public static function getInstance(): static
			{
				return new static();
			}

			public function findEventHandlers(string $moduleId, string $eventType): array
			{
				return [];
			}

			public function registerEventHandler(string $fromModule, string $event, string $toModule, string $class, string $method, int $sort = 100): void
			{
				static::$registered[] = [$fromModule, $event, $toModule, $class, $method];
			}

			public function registerEventHandlerCompatible(string $fromModule, string $event, string $toModule, string $class, string $method, int $sort = 100): void
			{
				static::$registered[] = [$fromModule, $event, $toModule, $class, $method];
			}

			public function unRegisterEventHandler(string $fromModule, string $event, string $toModule, string $class, string $method): void
			{
				static::$unregistered[] = [$fromModule, $event, $toModule, $class, $method];
			}
		}

		class ModuleManager
		{
			/** @var string[] */
			public static array $installed = [];

			public static function isModuleInstalled(string $moduleId): bool
			{
				return in_array($moduleId, static::$installed, true);
			}

			public static function getInstalledModules(): array
			{
				return array_fill_keys(static::$installed, []);
			}

			/** @var array<string, string> версии модулей (Maintenance: требование к shef.toolsai) */
			public static array $versions = [];

			public static function getVersion(string $moduleId): string|false
			{
				return static::$versions[$moduleId] ?? false;
			}
		}
	}
}

