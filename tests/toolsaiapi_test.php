<?php declare(strict_types=1);

/**
 * Что модуль берёт у shef.toolsai — существует ли оно.
 *
 * Список — таблица docs/guide/03-dependencies.md. Есть рядом исходники
 * shef.toolsai (переменная TOOLSAI_DIR, ../toolsai или /home/user/toolsai) —
 * сверяем с ними: класс в своём файле и метод в нём. Нет (CI этого
 * репозитория) — сверяем, что список и документация не разошлись: каждый
 * пункт назван в таблице.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';

/** [файл от корня shef.toolsai, класс, метод или константа] */
const TOOLSAI_API = [
	['lib/prompt/registryservice.php', 'RegistryService', 'function registry('],
	['lib/prompt/registryservice.php', 'RegistryService', 'function compose('],
	['lib/prompt/registryservice.php', 'RegistryService', 'function forget('],
	['lib/prompt/registryservice.php', 'RegistryService', "EVENT_BUILD = 'OnPromptRegistryBuild'"],
	['lib/prompt/registry.php', 'Registry', 'function roles('],
	['lib/prompt/definition.php', 'Definition', 'PLACE_LIST'],
	['lib/prompt/definition.php', 'Definition', 'PLACE_DOCS'],
	['lib/analysis/recordhistory.php', 'RecordHistory', 'function get(int $entityTypeId, int $id, int $userId'],
	['lib/container.php', 'Container', 'function getLlm('],
	['lib/container.php', 'Container', 'function getMeter('],
	['lib/container.php', 'Container', 'function getConfig('],
	['lib/config.php', 'Config', 'DIRECTION_ASSISTANT'],
	['lib/config.php', 'Config', 'function getAssistantEndpoint('],
	['lib/provider/llm/llmproviderinterface.php', 'LlmProviderInterface', 'function complete(array $messages)'],
	['lib/provider/llm/llmresult.php', 'LlmResult', 'function getTokens('],
	['lib/main/constants.php', 'Constants', 'PROVIDER_ECHO'],
	['lib/main/constants.php', 'Constants', 'function getEngineCode('],
	['lib/quota/balance.php', 'Balance', 'function isExceeded('],
	['lib/quota/ledger.php', 'Ledger', 'function start(string $engineCode, string $category, string $providerCode, ?string $jobHash'],
	['lib/quota/ledger.php', 'Ledger', 'function finish(int $id, string $status'],
	['lib/quota/status.php', 'Status', 'SUCCESS'],
	['lib/quota/status.php', 'Status', 'ERROR'],
	['lib/provider/providerexception.php', 'ProviderException', 'spentMicro'],
	['lib/log/handlers.php', 'Handlers', 'function withoutFiles('],
];

$toolsai = null;
foreach([getenv('TOOLSAI_DIR') ?: '', dirname($root).'/toolsai', '/home/user/toolsai'] as $dir)
{
	if($dir !== '' && is_file($dir.'/lib/prompt/registryservice.php'))
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
