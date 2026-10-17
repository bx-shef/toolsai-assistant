<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant;

use Psr\Log\LoggerInterface;
use Shef\ToolsAiAssistant\Chat\AnswerService;
use Shef\ToolsAiAssistant\Chat\WidgetCatalog;
use Shef\ToolsAiAssistant\Chat\Gateway\ImMessenger;
use Shef\ToolsAiAssistant\Chat\Gateway\KbSource;
use Shef\ToolsAiAssistant\Chat\Gateway\MessengerInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\RateStoreInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiModelGateway;
use Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiPromptSource;
use Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiRecordSource;
use Shef\ToolsAiAssistant\Chat\Gateway\UserOptionRateStore;
use Shef\ToolsAiAssistant\Chat\RoleChoiceStore;
use Shef\ToolsAiAssistant\Feedback\FeedbackStore;
use Shef\ToolsAiAssistant\Main\Constants;
use Shef\ToolsAiAssistant\Agent\AgentEvents;
use Shef\ToolsAiAssistant\Agent\AgentService;
use Shef\ToolsAiAssistant\Agent\PendingStore;
use Shef\ToolsAiAssistant\Mcp\BitrixHttpTransport;
use Shef\ToolsAiAssistant\Mcp\McpConnection;
use Shef\ToolsAiAssistant\Mcp\OptionStateStore;
use Shef\ToolsAiAssistant\Mcp\SessionStateStore;
use Shef\ToolsAiAssistant\Mcp\TokenStore;

/**
 * Сборка сервисов модуля на портале: реальные шлюзы к shef.toolsai и
 * shef.toolsaikb. Тесты собирают AnswerService сами — с подменами.
 */
final class Container
{
	private static ?AnswerService $answerService = null;
	private static ?MessengerInterface $messenger = null;
	private static ?RateStoreInterface $greetRates = null;
	private static ?\DateTimeImmutable $now = null;

	/** Мессенджер портала для поздравлений (1.11.0) или подмена теста. */
	public static function getMessenger(): MessengerInterface
	{
		return self::$messenger ?? new ImMessenger();
	}

	/** Отметки отправленных поздравлений сотрудника — для RateLimit (1.11.0). */
	public static function getGreetRates(): RateStoreInterface
	{
		return self::$greetRates ?? new UserOptionRateStore('rate_greet');
	}

	/** Сейчас — время сервера или подмена теста. */
	public static function now(): \DateTimeImmutable
	{
		return self::$now ?? new \DateTimeImmutable();
	}

	/** Подменить мессенджер, отметки и время (тесты контроллера); null — вернуть настоящие. */
	public static function setGreetDeps(?MessengerInterface $messenger, ?RateStoreInterface $rates = null, ?\DateTimeImmutable $now = null): void
	{
		self::$messenger = $messenger;
		self::$greetRates = $rates;
		self::$now = $now;
	}

	/** Сценарий чата с настройками портала (или подменённый тестом). */
	public static function getAnswerService(): AnswerService
	{
		return self::$answerService ?? new AnswerService(
			new ToolsAiPromptSource(),
			new ToolsAiRecordSource(),
			new KbSource(),
			new ToolsAiModelGateway(),
			new RoleChoiceStore(),
			new UserOptionRateStore(),
			Config::current(),
			null,
			new FeedbackStore(),
			WidgetCatalog::instructions(WidgetCatalog::collect()),
		);
	}

	/** Подключение сотрудников к MCP-серверу из настроек (1.10.0). */
	public static function getMcpConnection(?Config $config = null): McpConnection
	{
		return new McpConnection(($config ?? Config::current())->agentUrl, new BitrixHttpTransport(), new TokenStore(), new SessionStateStore(), new OptionStateStore());
	}

	/** Режим «Агент» (1.10.0): цикл инструментов MCP поверх сценария чата. */
	public static function getAgentService(): AgentService
	{
		$config = Config::current();

		return new AgentService(static::getAnswerService(), new ToolsAiModelGateway(), static::getMcpConnection($config), new PendingStore(), new AgentEvents(), $config);
	}

	/** Подменить сценарий (тесты контроллера); null — вернуть настоящий. */
	public static function setAnswerService(?AnswerService $service): void
	{
		self::$answerService = $service;
	}

	/**
	 * Логгер проблем shef.problems — как у shef.toolsai: сбой ложится в журнал
	 * событий Битрикс24 с типом «Продажи и CRM» и ответственным из настроек
	 * shef.problems. Без shef.problems — null: чат важнее лога.
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
			return $logger;
		}
		catch(\Throwable)
		{
			return null;
		}
	}
}
