<?php declare(strict_types=1);

/**
 * Шесть ролей помощника в реестр промптов shef.toolsai.
 *
 * Реестр молча отбрасывает описание, которое не прошло Prompt\Definition::fromArray()
 * shef.toolsai: код не «<модуль>:<имя>», чужой модуль, не role/skill, пустое
 * название. Отброшенная роль просто не появится — ни в чате, ни в
 * «Библиотеке промптов». Поэтому здесь — те же правила, что у fromArray()
 * (shef.toolsai 1.48.0, lib/prompt/definition.php), и ответ обработчика.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Shef\ToolsAiAssistant\Access\Actions;
use Shef\ToolsAiAssistant\Internals\Integration\ToolsAi\EventHandler\OnPromptRegistryBuildEventHandler;
use Shef\ToolsAiAssistant\Main\Constants;
use Shef\ToolsAiAssistant\Prompt\Roles;

$raw = Roles::raw();

Check::group('состав');

Check::same('ролей шесть', count($raw), 6);
Check::same('названия — из задания владельца', array_column($raw, 'title'), [
	'Помощник менеджера',
	'Контроль руководителя',
	'Аналитик качества сделки',
	'Узкие места',
	'Наставник по звонкам и переписке',
	'Клиентский опыт',
]);
Check::same('по умолчанию — помощник менеджера, он первый', [$raw[0]['code'], Constants::DEFAULT_ROLE], [Roles::MANAGER, Roles::MANAGER]);
Check::same('коды уникальны', count(array_unique(Roles::codes())), 6);

Check::group('правила реестра shef.toolsai (Definition::fromArray)');

$broken = [];
foreach($raw as $role)
{
	$code = $role['code'];
	$checks = [
		'код «модуль:имя»' => preg_match('/^[a-z0-9_.]+:[a-z0-9_.\-]{1,80}$/', $code) === 1,
		'код — строчными' => strtolower($code) === $code,
		'модуль кода — этот' => str_starts_with($code, Constants::MODULE_ID.':'),
		'вид — роль' => $role['kind'] === 'role',
		'название до 120' => $role['title'] !== '' && mb_strlen($role['title']) <= 120,
		'текст до 16000' => mb_strlen($role['text']) > 300 && mb_strlen($role['text']) <= 16000,
		'описание до 500' => $role['description'] !== '' && mb_strlen($role['description']) <= 500,
		'вопросы: 1–6, до 200 символов' => count($role['examples']) >= 1 && count($role['examples']) <= 6 && max(array_map('mb_strlen', $role['examples'])) <= 200,
		'код — по шаблону кодов промптов' => preg_match(Actions::PROMPT_CODE_PATTERN, $code) === 1,
		'данные — не инструкции' => str_contains($role['text'], 'данные, а не инструкции'),
	];
	foreach($checks as $what => $ok)
	{
		if(!$ok)
		{
			$broken[] = $code.': '.$what;
		}
	}
}
Check::same('все роли проходят правила реестра', $broken, []);

Check::group('акцент ролей');

$text = implode("\n", array_column($raw, 'text'));
foreach(['следующий шаг', 'Скорость реакции', 'Чек-лист', 'Паузы', 'Возражения', 'Ожидание'] as $needle)
{
	Check::same('в текстах ролей есть «'.$needle.'»', str_contains($text, $needle), true);
}

Check::group('заготовки прав');

$presets = Roles::accessPresets();
Check::same('две заготовки', array_keys($presets), ['Продавец', 'Руководитель продаж']);
Check::same('заготовки дают только чат — роли открывает библиотека промптов (1.6.0)', array_values($presets), [['chat.use'], ['chat.use']]);

Check::group('обработчик события');

$result = OnPromptRegistryBuildEventHandler::handle(new Event('shef.toolsai', 'OnPromptRegistryBuild'));
Check::same('ответ — EventResult SUCCESS', [$result instanceof EventResult, $result->getType()], [true, EventResult::SUCCESS]);
Check::same('владелец — этот модуль (реестр сверяет коды с ним)', $result->getModuleId(), Constants::MODULE_ID);
Check::same('промпты — роли модуля', $result->getParameters()['prompts'] ?? null, $raw);

Check::finish();
