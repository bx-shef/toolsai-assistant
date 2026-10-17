<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Main;

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;

/**
 * Обновление без установщика (1.1.0) — как Settings\Maintenance shef.toolsai.
 *
 * На портале модуль обновляют копированием файлов в bitrix/modules, установщик
 * при этом не запускается. Тогда новые файлы из installDir (JS-расширение,
 * страницы /shef/toolsaiassistant/…), таблицы и подписки на портал не попадут.
 * Поэтому при первом хите после смены версии (OnProlog, страница настроек)
 * модуль доводит себя сам:
 *
 *   - перекопирует installDir из .settings.php — только свои пути
 *     (allowedTarget()), с заменой файлов;
 *   - создаёт недостающие таблицы;
 *   - ставит подписки installEvents (registerEventHandler ядра идемпотентен:
 *     INSERT IGNORE по UNIQUE_ID, main/lib/eventmanager.php);
 *   - ставит агент очистки оценок;
 *   - удаляет устаревшие опции (OBSOLETE_OPTIONS).
 *
 * Версия файлов, до которой довели, — опция FILES_version. Совпала с версией
 * модуля — ничего не делаем (одно чтение опции из кеша ядра). Не всё
 * получилось — версию не пишем: попробуем на следующем хите. Исключений
 * наружу нет: сбой здесь не должен ронять страницу.
 */
final class Maintenance
{
	public const OPTION_VERSION = 'FILES_version';

	/** Опции, которые больше не нужны (1.2.0: отметка переноса прав из shef.toolsai). */
	public const OBSOLETE_OPTIONS = ['LEGACY_migrated'];

	/** Куда installDir может писать: свои подкаталоги, не чужие. */
	public const ALLOWED_TARGETS = ['/bitrix/js/shef/toolsaiassistant', '/local/components/shef/toolsaiassistant.', '/shef/toolsaiassistant'];

	private static bool $checked = false;

	/** Раз за хит: версия файлов отстала — довести. */
	public static function ensureOnce(): void
	{
		if(self::$checked)
		{
			return;
		}
		self::$checked = true;
		try
		{
			$moduleDir = dirname(__DIR__, 2);
			if(static::isNeeded((string)Option::get(Constants::MODULE_ID, self::OPTION_VERSION, ''), static::moduleVersion($moduleDir)))
			{
				static::ensure((string)\Bitrix\Main\Application::getDocumentRoot(), $moduleDir);
			}
		}
		catch(\Throwable)
		{
		}
	}

	/** Нужно ли доводить: сохранённая версия файлов не та, что у модуля. */
	public static function isNeeded(string $saved, string $current): bool
	{
		return $current !== '' && $saved !== $current;
	}

	/**
	 * Довести файлы, таблицы, подписки и агент до версии модуля.
	 *
	 * @param (\Closure(): void)|null $tables создание таблиц (тесты подменяют)
	 * @param (\Closure(): void)|null $agents агенты (тесты подменяют)
	 * @return array<string, bool> что сделано => получилось
	 */
	public static function ensure(string $documentRoot, string $moduleDir, ?\Closure $tables = null, ?\Closure $agents = null): array
	{
		$settings = static::settings($moduleDir);
		$report = [];

		foreach(static::copyPlan((array)($settings['installDir'] ?? [])) as [$from, $to])
		{
			try
			{
				$report['files '.$to] = is_dir($moduleDir.$from) && \CopyDirFiles($moduleDir.$from, $documentRoot.$to, true, true) !== false;
			}
			catch(\Throwable)
			{
				$report['files '.$to] = false;
			}
		}

		$steps = [
			'tables' => $tables ?? static function(): void
			{
				\Shef\ToolsAiAssistant\Access\RoleStore::install();
				\Shef\ToolsAiAssistant\Chat\RoleChoiceStore::install();
				\Shef\ToolsAiAssistant\Feedback\FeedbackStore::install();
			},
			'events' => static function() use ($settings): void
			{
				static::registerEvents((array)($settings['installEvents'] ?? []));
				// Меню карточки CRM кешируется — сбросить, чтобы пункт чата появился (1.3.2)
				\Shef\ToolsAiAssistant\Internals\Integration\Intranet\EventHandler\OnBuildBindingMenuEventHandler::clearCache();
			},
			'agents' => $agents ?? static function(): void
			{
				\Shef\ToolsAiAssistant\Feedback\FeedbackStore::installAgent();
			},
			'options' => static function(): void
			{
				foreach(self::OBSOLETE_OPTIONS as $name)
				{
					Option::delete(Constants::MODULE_ID, ['name' => $name]);
				}
			},
		];
		foreach($steps as $name => $step)
		{
			try
			{
				$step();
				$report[$name] = true;
			}
			catch(\Throwable)
			{
				$report[$name] = false;
			}
		}

		if(!in_array(false, $report, true))
		{
			Option::set(Constants::MODULE_ID, self::OPTION_VERSION, static::moduleVersion($moduleDir));
		}

		return $report;
	}

	/**
	 * Что копировать: [из (от каталога модуля), куда (от корня сайта)].
	 * Каталоги из installDir раскладываются целиком, поэтому копируем только
	 * свои подкаталоги источника: install/js/shef/toolsaiassistant →
	 * /bitrix/js/shef/toolsaiassistant и т.д. Чужие пути — мимо.
	 *
	 * @param list<array{from?: string, to?: string, customPathUnInstall?: list<string>}> $dirs
	 * @return list<array{0: string, 1: string}>
	 */
	public static function copyPlan(array $dirs): array
	{
		$plan = [];
		foreach($dirs as $dir)
		{
			$from = (string)($dir['from'] ?? '');
			$to = (string)($dir['to'] ?? '');
			if(!str_starts_with($from, '/install/') || str_contains($from.$to, '..'))
			{
				continue;
			}
			// Свой подкаталог — тот, что удаляется при удалении модуля
			foreach((array)($dir['customPathUnInstall'] ?? []) as $own)
			{
				$own = (string)$own;
				if($to !== '' && !str_starts_with($own, $to.'/'))
				{
					continue;
				}
				if(!static::allowedTarget($own))
				{
					continue;
				}
				$plan[] = [$from.substr($own, strlen($to)), $own];
			}
		}

		return $plan;
	}

	/** Путь — свой подкаталог модуля. */
	public static function allowedTarget(string $path): bool
	{
		if(str_contains($path, '..'))
		{
			return false;
		}
		foreach(self::ALLOWED_TARGETS as $prefix)
		{
			if(str_starts_with($path, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	/** Подписки из installEvents — как InstallEvents() установщика. */
	public static function registerEvents(array $events): void
	{
		$manager = EventManager::getInstance();
		foreach($events as $event)
		{
			$method = !empty($event['isCompatible']) ? 'registerEventHandlerCompatible' : 'registerEventHandler';
			$manager->{$method}(
				$event['from']['module'],
				$event['from']['event'],
				$event['to']['module'],
				$event['to']['class'],
				$event['to']['function'],
				(int)($event['to']['sort'] ?? 100)
			);
		}
	}

	/** Версия модуля из install/version.php. */
	public static function moduleVersion(string $moduleDir): string
	{
		$arModuleVersion = [];
		$file = $moduleDir.'/install/version.php';
		if(is_file($file))
		{
			include $file;
		}

		return (string)($arModuleVersion['VERSION'] ?? '');
	}

	/** Значения .settings.php (ключ => value). */
	private static function settings(string $moduleDir): array
	{
		$settings = include $moduleDir.'/.settings.php';
		$out = [];
		foreach(is_array($settings) ? $settings : [] as $key => $item)
		{
			$out[$key] = is_array($item) ? ($item['value'] ?? null) : null;
		}

		return $out;
	}
}
