<?php declare(strict_types=1);

/**
 * Что модуль берёт у shef.toolsai — существует ли оно.
 *
 * Список — таблица docs/guide/03-dependencies.md: публичный API
 * Shef\\ToolsAi\\Public\\… (1.54.0) и событие реестра промптов. Есть рядом исходники
 * shef.toolsai (переменная TOOLSAI_DIR, ../toolsai или /home/user/toolsai) —
 * сверяем с ними: класс в своём файле и метод в нём. Нет (CI этого
 * репозитория) — сверяем, что список и документация не разошлись: каждый
 * пункт назван в таблице.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';

/** [файл от корня shef.toolsai, класс, метод или константа] */
const TOOLSAI_API = [
	['lib/public/service/llmservice.php', 'LlmService', 'function complete(string $category, array $messages, ?int $userId = null): LlmResponse'],
	['lib/public/service/llmservice.php', 'LlmService', 'function stream(string $category, array $messages, callable $onDelta, ?int $userId = null): LlmResponse'],
	['lib/public/service/llmservice.php', 'LlmService', 'function model(string $category): string'],
	['lib/public/service/llmservice.php', 'LlmService', "CATEGORY_ASSISTANT = 'assistant'"],
	['lib/public/service/llmservice.php', 'LlmService', "ERROR_QUOTA = 'quota_exceeded'"],
	['lib/public/service/llmservice.php', 'LlmService', "ERROR_NO_MODEL = 'no_model'"],
	['lib/public/service/llmservice.php', 'LlmService', "ERROR_PROVIDER = 'provider_error'"],
	['lib/public/service/llmservice.php', 'LlmService', "ERROR_EMPTY = 'empty_answer'"],
	['lib/public/service/llmresponse.php', 'LlmResponse', 'function getText(): string'],
	['lib/public/service/llmresponse.php', 'LlmResponse', 'function getFinishReason(): string'],
	['lib/public/service/llmresponse.php', 'LlmResponse', 'function getErrorCode(): string'],
	['lib/public/service/promptservice.php', 'PromptService', 'function roles(?array $allowed = null, int $userId = self::USER_CURRENT): array'],
	['lib/public/service/promptservice.php', 'PromptService', 'function compose(string $roleCode, string $place, int $entityTypeId = 0, ?array $allowed = null, int $userId = self::USER_CURRENT): array'],
	['lib/public/service/promptservice.php', 'PromptService', 'function isAvailable(string $code, int $userId = self::USER_CURRENT): bool'],
	['lib/public/service/promptservice.php', 'PromptService', 'function get(string $code): ?array'],
	['lib/public/service/promptservice.php', 'PromptService', 'USER_CURRENT = -1'],
	['lib/public/service/promptservice.php', 'PromptService', "'iconUrl' =>"],
	['lib/public/service/promptservice.php', 'PromptService', "'favorite' =>"],
	['lib/public/service/promptservice.php', 'PromptService', 'function forget(): void'],
	['lib/public/service/promptservice.php', 'PromptService', 'PLACE_ASSISTANT'],
	// 1.7.0: меню промптов — shef.toolsai 1.58.0 (старее — меню нет, method_exists)
	['lib/public/service/promptservice.php', 'PromptService', 'function skills(string $roleCode, array $places, int $entityTypeId = 0, int $userId = self::USER_CURRENT): array'],
	['lib/prompt/places.php', 'Places', "EVENT = 'OnPromptPlacesCollect'"],
	['lib/public/service/recordservice.php', 'RecordService', 'function get(int $entityTypeId, int $id, int $userId): ?array'],
	['lib/public/event/viewrowactionsbuildevent.php', 'ViewRowActionsBuildEvent', "TYPE = 'OnViewRowActionsBuild'"],
	['lib/prompt/registryservice.php', 'RegistryService', "EVENT_BUILD = 'OnPromptRegistryBuild'"],
];

$toolsai = null;
foreach([getenv('TOOLSAI_DIR') ?: '', dirname($root).'/toolsai', '/home/user/toolsai'] as $dir)
{
	if($dir !== '' && is_file($dir.'/lib/public/service/llmservice.php'))
	{
		$toolsai = $dir;
		break;
	}
}

if($toolsai !== null)
{
	Check::group('сверка с исходниками shef.toolsai: '.$toolsai);

	$missing = [];
	foreach(TOOLSAI_API as [$file, $class, $member])
	{
		$code = is_file($toolsai.'/'.$file) ? (string)file_get_contents($toolsai.'/'.$file) : '';
		if(!preg_match('/\b(class|interface)\s+'.preg_quote($class, '/').'\b/', $code) || !str_contains($code, $member))
		{
			$missing[] = $file.': '.$class.' '.$member;
		}
	}
	Check::same('классы и методы shef.toolsai на месте', $missing, []);
}
else
{
	Check::group('исходников shef.toolsai рядом нет — сверка списка с документацией');
}

$doc = (string)file_get_contents($root.'/docs/guide/03-dependencies.md');
$undocumented = [];
foreach(TOOLSAI_API as [, $class])
{
	if(!str_contains($doc, $class))
	{
		$undocumented[] = $class;
	}
}
Check::same('каждый класс списка назван в 03-dependencies.md', array_values(array_unique($undocumented)), []);

Check::finish();
