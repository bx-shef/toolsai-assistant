<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant;

use Psr\Log\LoggerInterface;
use Shef\ToolsAiAssistant\Chat\AnswerService;
use Shef\ToolsAiAssistant\Chat\Gateway\KbSource;
use Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiModelGateway;
use Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiPromptSource;
use Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiRecordSource;
use Shef\ToolsAiAssistant\Chat\RoleChoiceStore;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Сборка сервисов модуля на портале: реальные шлюзы к shef.toolsai и
 * shef.toolsaikb. Тесты собирают AnswerService сами — с подменами.
 */
final class Container
{
	/** Сценарий чата с настройками портала. */
	public static function getAnswerService(): AnswerService
	{
		return new AnswerService(
			new ToolsAiPromptSource(),
			new ToolsAiRecordSource(),
			new KbSource(),
			new ToolsAiModelGateway(),
			new RoleChoiceStore(),
			Config::current(),
		);
	}

	/**
	 * Логгер проблем shef.problems — как у shef.toolsai: сбой ложится в журнал
	 * событий Битрикс24 с типом «Продажи и CRM» и ответственным из настроек
	 * shef.problems, без файла каталога логов (Log\Handlers::withoutFiles()
 * shef.toolsai). Без shef.problems — null: чат важнее лога.
	 */
	public static function getLogger(string $className): ?LoggerInterface
	{
		try
		{
			if(!\Bitrix\Main\Loader::includeModule('shef.problems'))
			{
				return null;
			}

			$logger = \Shef\Problems\Factory\SystemLoggerFactory::build(
				logLevel: \Monolog\Level::Warning,
				auditType: \Shef\Problems\Main\Constants::AuditTypeSale,
				moduleId: Constants::MODULE_ID,
				className: $className,
				assigned: \Shef\Problems\Main\Constants::getSaleUserId(),
			);
			// Только журнал событий Битрикс24, без файла каталога логов — как у shef.toolsai
			if(class_exists(\Shef\ToolsAi\Log\Handlers::class))
			{
				$logger->setHandlers(\Shef\ToolsAi\Log\Handlers::withoutFiles($logger->getHandlers()));
			}

			return $logger;
		}
		catch(\Throwable)
		{
			return null;
		}
	}
}
